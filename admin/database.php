<?php
/*
|--------------------------------------------------------------------------
| Developer Database Manager
|--------------------------------------------------------------------------
| Recommended location:
| /admin/database.php
|
| This file expects your PDO connection here:
| ../config/database.php
|
| IMPORTANT:
| - Use only during development.
| - Protect with login or remove before production.
|--------------------------------------------------------------------------
*/

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";

// This is a raw SQL-capable database manager — restricted to the admin role
// only (not factory_user/accountant, who pass requireStaffLogin elsewhere).
// The previous dev-token bypass left a hardcoded, never-rotated placeholder
// token (REPLACE_WITH_A_LONG_RANDOM_STRING_BEFORE_UPLOADING) guarding this —
// effectively unauthenticated access to the whole database. Removed in favor
// of real session-based login, same as every other admin page.
$currentStaff = requireStaffLogin($pdo);
if (($currentStaff["role"] ?? null) !== "admin") {
    http_response_code(403);
    die("Access denied. This tool is restricted to admin accounts.");
}

if (empty($_SESSION["db_manager_csrf"])) {
    $_SESSION["db_manager_csrf"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["db_manager_csrf"];

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function redirectTo($url)
{
    header("Location: " . $url);
    exit;
}

function setMessage($type, $message)
{
    $_SESSION["db_manager_" . $type] = $message;
}

function getMessage($type)
{
    $key = "db_manager_" . $type;
    if (!empty($_SESSION[$key])) {
        $message = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $message;
    }
    return null;
}

function quoteIdentifier($identifier)
{
    return "`" . str_replace("`", "``", $identifier) . "`";
}

function getDatabaseName($pdo)
{
    $stmt = $pdo->query("SELECT DATABASE() AS db_name");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row["db_name"] : "";
}

function getTables($pdo)
{
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $row) {
        $tables[] = $row[0];
    }
    return $tables;
}

function tableExistsInList($table, $tables)
{
    return in_array($table, $tables, true);
}

function getColumns($pdo, $table)
{
    $stmt = $pdo->query("SHOW FULL COLUMNS FROM " . quoteIdentifier($table));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPrimaryKeyColumn($columns)
{
    $primaryKeys = [];
    foreach ($columns as $column) {
        if (($column["Key"] ?? "") === "PRI") {
            $primaryKeys[] = $column["Field"];
        }
    }
    if (count($primaryKeys) === 1) {
        return $primaryKeys[0];
    }
    return null;
}

function getEditableColumns($columns)
{
    $editable = [];
    foreach ($columns as $column) {
        $extra = strtolower($column["Extra"] ?? "");
        if (str_contains($extra, "auto_increment")) continue;
        if (str_contains($extra, "generated")) continue;
        $editable[] = $column;
    }
    return $editable;
}

function getColumnNames($columns)
{
    return array_map(function ($column) {
        return $column["Field"];
    }, $columns);
}

function isValidColumn($column, $columns)
{
    return in_array($column, getColumnNames($columns), true);
}

function getTableRowCount($pdo, $table)
{
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM " . quoteIdentifier($table));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row["total"] : 0;
}

function getRows($pdo, $table, $limit = 50, $offset = 0, $primaryKey = null)
{
    $limit = (int)$limit;
    $offset = (int)$offset;
    if ($limit < 1) $limit = 50;
    if ($limit > 200) $limit = 200;
    if ($offset < 0) $offset = 0;

    $orderSql = "";
    if ($primaryKey) {
        $orderSql = " ORDER BY " . quoteIdentifier($primaryKey) . " DESC ";
    }

    $sql = "SELECT * FROM " . quoteIdentifier($table) . " $orderSql LIMIT $limit OFFSET $offset";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSingleRow($pdo, $table, $primaryKey, $primaryValue)
{
    $stmt = $pdo->prepare("
        SELECT * FROM " . quoteIdentifier($table) . "
        WHERE " . quoteIdentifier($primaryKey) . " = :primary_value
        LIMIT 1
    ");
    $stmt->execute([":primary_value" => $primaryValue]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function parseEnumValues($type)
{
    $values = [];
    if (preg_match("/^enum\((.*)\)$/i", $type, $matches)) {
        $enumString = $matches[1];
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $enumString, $enumMatches);
        foreach ($enumMatches[1] as $value) {
            $values[] = stripcslashes($value);
        }
    }
    return $values;
}

function getInputType($columnType)
{
    $type = strtolower($columnType);
    if (str_contains($type, "int") || str_contains($type, "decimal") || str_contains($type, "float") || str_contains($type, "double")) {
        return "number";
    }
    if (str_contains($type, "date") && !str_contains($type, "datetime") && !str_contains($type, "timestamp")) {
        return "date";
    }
    return "text";
}

function prepareValueForDatabase($rawValue, $column, $isInsert = false)
{
    $value = trim((string)$rawValue);
    $isNullable = strtoupper($column["Null"] ?? "NO") === "YES";
    $default = $column["Default"] ?? null;

    if ($value === "") {
        if ($isInsert && $default !== null) {
            return ["skip" => true, "value" => null];
        }
        if ($isNullable) {
            return ["skip" => false, "value" => null];
        }
        return ["skip" => false, "value" => ""];
    }

    return ["skip" => false, "value" => $value];
}

function renderColumnInput($column, $value = "")
{
    $field = $column["Field"];
    $type = strtolower($column["Type"] ?? "");
    $comment = $column["Comment"] ?? "";
    $enumValues = parseEnumValues($type);

    echo '<div class="mb-3">';
    echo '<label class="form-label fw-semibold">' . h($field) . '</label>';

    if (!empty($enumValues)) {
        echo '<select name="fields[' . h($field) . ']" class="form-select">';
        echo '<option value="">-- Select --</option>';
        foreach ($enumValues as $enumValue) {
            $selected = ((string)$value === (string)$enumValue) ? "selected" : "";
            echo '<option value="' . h($enumValue) . '" ' . $selected . '>' . h($enumValue) . '</option>';
        }
        echo '</select>';
    } elseif (str_contains($type, "text")) {
        echo '<textarea name="fields[' . h($field) . ']" class="form-control" rows="3">' . h($value) . '</textarea>';
    } else {
        $inputType = getInputType($type);
        $step = "";
        if ($inputType === "number" && (str_contains($type, "decimal") || str_contains($type, "float") || str_contains($type, "double"))) {
            $step = 'step="0.001"';
        }
        echo '<input type="' . h($inputType) . '" name="fields[' . h($field) . ']" class="form-control" value="' . h($value) . '" ' . $step . '>';
    }

    echo '<small class="text-muted">';
    echo 'Type: ' . h($column["Type"]);
    if (($column["Null"] ?? "NO") === "YES") {
        echo ' | Nullable';
    } else {
        echo ' | Required';
    }
    if ($column["Default"] !== null) {
        echo ' | Default: ' . h($column["Default"]);
    }
    if ($comment !== "") {
        echo ' | ' . h($comment);
    }
    echo '</small>';
    echo '</div>';
}

/*
|--------------------------------------------------------------------------
| SQL Export Function
|--------------------------------------------------------------------------
*/
function generateSqlExport($pdo, $databaseName, $exportTables, $includeDropTable, $includeData)
{
    $output = [];

    $output[] = "-- ============================================================";
    $output[] = "-- Database Export: " . $databaseName;
    $output[] = "-- Generated: " . date("Y-m-d H:i:s");
    $output[] = "-- ============================================================";
    $output[] = "";
    $output[] = "SET FOREIGN_KEY_CHECKS=0;";
    $output[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
    $output[] = "SET NAMES utf8mb4;";
    $output[] = "";

    foreach ($exportTables as $table) {
        $output[] = "-- ------------------------------------------------------------";
        $output[] = "-- Table: " . $table;
        $output[] = "-- ------------------------------------------------------------";
        $output[] = "";

        // DROP TABLE statement
        if ($includeDropTable) {
            $output[] = "DROP TABLE IF EXISTS " . quoteIdentifier($table) . ";";
        }

        // CREATE TABLE statement
        $stmt = $pdo->query("SHOW CREATE TABLE " . quoteIdentifier($table));
        $row = $stmt->fetch(PDO::FETCH_NUM);
        if ($row) {
            $output[] = $row[1] . ";";
        }
        $output[] = "";

        // INSERT DATA
        if ($includeData) {
            $stmt = $pdo->query("SELECT * FROM " . quoteIdentifier($table));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                // Get column names
                $colNames = array_map(function($col) {
                    return quoteIdentifier($col);
                }, array_keys($rows[0]));

                $output[] = "-- Data for table: " . $table;

                // Chunk inserts in batches of 100
                $chunks = array_chunk($rows, 100);
                foreach ($chunks as $chunk) {
                    $valueGroups = [];
                    foreach ($chunk as $dataRow) {
                        $values = [];
                        foreach ($dataRow as $val) {
                            if ($val === null) {
                                $values[] = "NULL";
                            } else {
                                $values[] = "'" . addslashes($val) . "'";
                            }
                        }
                        $valueGroups[] = "(" . implode(", ", $values) . ")";
                    }
                    $output[] = "INSERT INTO " . quoteIdentifier($table) . " (" . implode(", ", $colNames) . ") VALUES";
                    $output[] = implode(",\n", $valueGroups) . ";";
                }
                $output[] = "";
            } else {
                $output[] = "-- No data in table: " . $table;
                $output[] = "";
            }
        }
    }

    $output[] = "SET FOREIGN_KEY_CHECKS=1;";
    $output[] = "";
    $output[] = "-- Export complete.";

    return implode("\n", $output);
}

/*
|--------------------------------------------------------------------------
| Handle SQL Export Download
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["export_sql_action"])) {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $postedToken)) {
        setMessage("error", "Invalid security token.");
        redirectTo("database.php?mode=export");
    }

    $allTables = getTables($pdo);
    $dbName    = getDatabaseName($pdo);

    $selectedExportTables = $_POST["export_tables"] ?? [];
    $includeDropTable     = isset($_POST["include_drop"]);
    $includeData          = isset($_POST["include_data"]);

    // Validate selected tables
    $validTables = array_filter($selectedExportTables, function($t) use ($allTables) {
        return tableExistsInList($t, $allTables);
    });

    if (empty($validTables)) {
        setMessage("error", "Please select at least one table to export.");
        redirectTo("database.php?mode=export");
    }

    try {
        $sqlContent = generateSqlExport($pdo, $dbName, array_values($validTables), $includeDropTable, $includeData);

        $filename = $dbName . "_export_" . date("Ymd_His") . ".sql";

        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
        header("Content-Length: " . strlen($sqlContent));
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");

        echo $sqlContent;
        exit;
    } catch (PDOException $e) {
        setMessage("error", "Export failed: " . $e->getMessage());
        redirectTo("database.php?mode=export");
    }
}

/*
|--------------------------------------------------------------------------
| Load Tables
|--------------------------------------------------------------------------
*/
try {
    $databaseName = getDatabaseName($pdo);
    $tables = getTables($pdo);
} catch (PDOException $e) {
    die("Database connection error: " . h($e->getMessage()));
}

/*
|--------------------------------------------------------------------------
| Selected Table
|--------------------------------------------------------------------------
*/
$selectedTable = $_GET["table"] ?? "";
$selectedTable = trim($selectedTable);

if ($selectedTable !== "" && !tableExistsInList($selectedTable, $tables)) {
    $selectedTable = "";
}

$actionMode = $_GET["mode"] ?? "view";
$editId = $_GET["id"] ?? null;

/*
|--------------------------------------------------------------------------
| Handle CRUD Actions
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST["create_table_action"]) && !isset($_POST["drop_table_action"]) && !isset($_POST["sql_executor_action"]) && !isset($_POST["export_sql_action"])) {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $postedToken)) {
        setMessage("error", "Invalid security token.");
        redirectTo("database.php");
    }

    $postAction = $_POST["crud_action"] ?? "";
    $table = $_POST["table"] ?? "";

    if (!tableExistsInList($table, $tables)) {
        setMessage("error", "Invalid table selected.");
        redirectTo("database.php");
    }

    $columns = getColumns($pdo, $table);
    $primaryKey = getPrimaryKeyColumn($columns);
    $editableColumns = getEditableColumns($columns);

    try {
        if ($postAction === "insert") {
            $fields = $_POST["fields"] ?? [];
            $insertColumns = [];
            $insertPlaceholders = [];
            $params = [];

            foreach ($editableColumns as $column) {
                $field = $column["Field"];
                if (!isValidColumn($field, $columns)) continue;
                $prepared = prepareValueForDatabase($fields[$field] ?? "", $column, true);
                if ($prepared["skip"]) continue;
                $insertColumns[] = quoteIdentifier($field);
                $placeholder = ":field_" . count($params);
                $insertPlaceholders[] = $placeholder;
                $params[$placeholder] = $prepared["value"];
            }

            if (empty($insertColumns)) {
                setMessage("error", "No fields available for insert.");
                redirectTo("database.php?table=" . urlencode($table));
            }

            $sql = "INSERT INTO " . quoteIdentifier($table) . " (" . implode(", ", $insertColumns) . ") VALUES (" . implode(", ", $insertPlaceholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            setMessage("success", "Record inserted successfully.");
            redirectTo("database.php?table=" . urlencode($table));
        }

        if ($postAction === "update") {
            if (!$primaryKey) {
                setMessage("error", "This table does not have a single primary key. Edit is disabled.");
                redirectTo("database.php?table=" . urlencode($table));
            }

            $primaryValue = $_POST["primary_value"] ?? "";
            $fields = $_POST["fields"] ?? [];
            $setParts = [];
            $params = [":primary_value" => $primaryValue];

            foreach ($editableColumns as $column) {
                $field = $column["Field"];
                if ($field === $primaryKey) continue;
                if (!isValidColumn($field, $columns)) continue;
                $prepared = prepareValueForDatabase($fields[$field] ?? "", $column, false);
                $placeholder = ":field_" . count($params);
                $setParts[] = quoteIdentifier($field) . " = " . $placeholder;
                $params[$placeholder] = $prepared["value"];
            }

            if (empty($setParts)) {
                setMessage("error", "No fields available for update.");
                redirectTo("database.php?table=" . urlencode($table));
            }

            $sql = "UPDATE " . quoteIdentifier($table) . " SET " . implode(", ", $setParts) . " WHERE " . quoteIdentifier($primaryKey) . " = :primary_value LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            setMessage("success", "Record updated successfully.");
            redirectTo("database.php?table=" . urlencode($table));
        }

        if ($postAction === "delete") {
            if (!$primaryKey) {
                setMessage("error", "This table does not have a single primary key. Delete is disabled.");
                redirectTo("database.php?table=" . urlencode($table));
            }

            $primaryValue = $_POST["primary_value"] ?? "";
            $stmt = $pdo->prepare("DELETE FROM " . quoteIdentifier($table) . " WHERE " . quoteIdentifier($primaryKey) . " = :primary_value LIMIT 1");
            $stmt->execute([":primary_value" => $primaryValue]);

            setMessage("success", "Record deleted successfully.");
            redirectTo("database.php?table=" . urlencode($table));
        }

        setMessage("error", "Invalid action.");
        redirectTo("database.php?table=" . urlencode($table));
    } catch (PDOException $e) {
        setMessage("error", "Database error: " . $e->getMessage());
        redirectTo("database.php?table=" . urlencode($table));
    }
}

/*
|--------------------------------------------------------------------------
| Handle Create Table
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_table_action"])) {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $postedToken)) {
        setMessage("error", "Invalid security token.");
        redirectTo("database.php");
    }

    $newTableName   = trim($_POST["new_table_name"] ?? "");
    $columnNames    = $_POST["col_name"]    ?? [];
    $columnTypes    = $_POST["col_type"]    ?? [];
    $columnNulls    = $_POST["col_null"]    ?? [];
    $columnDefaults = $_POST["col_default"] ?? [];

    if ($newTableName === "") {
        setMessage("error", "Table name is required.");
        redirectTo("database.php?mode=create_table");
    }

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newTableName)) {
        setMessage("error", "Invalid table name. Use only letters, numbers, and underscores.");
        redirectTo("database.php?mode=create_table");
    }

    if (tableExistsInList($newTableName, $tables)) {
        setMessage("error", "A table with that name already exists.");
        redirectTo("database.php?mode=create_table");
    }

    $columnDefs = ["`id` INT AUTO_INCREMENT PRIMARY KEY"];

    foreach ($columnNames as $i => $colName) {
        $colName = trim($colName);
        if ($colName === "") continue;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colName)) {
            setMessage("error", "Invalid column name: " . $colName);
            redirectTo("database.php?mode=create_table");
        }

        $colType    = $columnTypes[$i]    ?? "VARCHAR(255)";
        $colNull    = isset($columnNulls[$i]) ? "NULL" : "NOT NULL";
        $colDefault = trim($columnDefaults[$i] ?? "");
        $def = quoteIdentifier($colName) . " " . $colType . " " . $colNull;
        if ($colDefault !== "") {
            $def .= " DEFAULT '" . addslashes($colDefault) . "'";
        }
        $columnDefs[] = $def;
    }

    $columnDefs[] = "`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP";

    try {
        $sql = "CREATE TABLE " . quoteIdentifier($newTableName) . " (\n" . implode(",\n", $columnDefs) . "\n)";
        $pdo->exec($sql);
        setMessage("success", "Table '" . $newTableName . "' created successfully.");
        redirectTo("database.php?table=" . urlencode($newTableName));
    } catch (PDOException $e) {
        setMessage("error", "Failed to create table: " . $e->getMessage());
        redirectTo("database.php?mode=create_table");
    }
}

/*
|--------------------------------------------------------------------------
| Handle Drop Table
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["drop_table_action"])) {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $postedToken)) {
        setMessage("error", "Invalid security token.");
        redirectTo("database.php");
    }

    $dropTable = trim($_POST["drop_table_name"] ?? "");
    $confirm   = trim($_POST["drop_confirm"] ?? "");

    if (!tableExistsInList($dropTable, $tables)) {
        setMessage("error", "Invalid table selected.");
        redirectTo("database.php");
    }

    if ($confirm !== $dropTable) {
        setMessage("error", "Confirmation name did not match. Table was NOT deleted.");
        redirectTo("database.php?table=" . urlencode($dropTable));
    }

    try {
        $pdo->exec("DROP TABLE " . quoteIdentifier($dropTable));
        setMessage("success", "Table '" . $dropTable . "' was permanently deleted.");
        redirectTo("database.php");
    } catch (PDOException $e) {
        setMessage("error", "Failed to drop table: " . $e->getMessage());
        redirectTo("database.php?table=" . urlencode($dropTable));
    }
}

/*
|--------------------------------------------------------------------------
| Handle SQL Query Executor
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["sql_executor_action"])) {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $postedToken)) {
        setMessage("error", "Invalid security token.");
        redirectTo("database.php?mode=sql");
    }

    $rawSql = trim($_POST["sql_query"] ?? "");

    if ($rawSql === "") {
        setMessage("error", "SQL query cannot be empty.");
        redirectTo("database.php?mode=sql");
    }

    $_SESSION["db_manager_last_sql"] = $rawSql;

    try {
        $stmt = $pdo->prepare($rawSql);
        $stmt->execute();

        $firstKeyword = strtoupper(strtok(ltrim($rawSql), " \t\n"));

        if (in_array($firstKeyword, ["SELECT", "SHOW", "DESCRIBE", "EXPLAIN", "DESC"])) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION["db_manager_sql_results"] = [
                "type"  => "select",
                "rows"  => $rows,
                "count" => count($rows),
                "sql"   => $rawSql,
            ];
        } else {
            $affected = $stmt->rowCount();
            $_SESSION["db_manager_sql_results"] = [
                "type"     => "exec",
                "affected" => $affected,
                "sql"      => $rawSql,
            ];
        }

        redirectTo("database.php?mode=sql");
    } catch (PDOException $e) {
        $_SESSION["db_manager_sql_results"] = [
            "type"  => "error",
            "error" => $e->getMessage(),
            "sql"   => $rawSql,
        ];
        redirectTo("database.php?mode=sql");
    }
}

/*
|--------------------------------------------------------------------------
| Page Data
|--------------------------------------------------------------------------
*/
$successMessage = getMessage("success");
$errorMessage = getMessage("error");

$selectedColumns = [];
$selectedRows = [];
$selectedPrimaryKey = null;
$selectedEditableColumns = [];
$totalRows = 0;
$editRow = null;

if ($selectedTable !== "") {
    try {
        $selectedColumns = getColumns($pdo, $selectedTable);
        $selectedPrimaryKey = getPrimaryKeyColumn($selectedColumns);
        $selectedEditableColumns = getEditableColumns($selectedColumns);
        $totalRows = getTableRowCount($pdo, $selectedTable);
        $selectedRows = getRows($pdo, $selectedTable, 100, 0, $selectedPrimaryKey);

        if ($actionMode === "edit" && $selectedPrimaryKey && $editId !== null) {
            $editRow = getSingleRow($pdo, $selectedTable, $selectedPrimaryKey, $editId);
        }
    } catch (PDOException $e) {
        $errorMessage = "Failed to load selected table: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Developer Database Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6f8; }
        .page-wrapper { padding: 24px; }
        .table-card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.05);
            margin-bottom: 18px;
        }
        .sidebar-card { position: sticky; top: 20px; }
        .table-name-link {
            text-decoration: none;
            color: #111;
            display: block;
            padding: 10px 12px;
            border-radius: 10px;
        }
        .table-name-link:hover, .table-name-link.active {
            background: #111;
            color: #fff;
        }
        .code-badge { font-family: Consolas, monospace; font-size: 12px; }
        .value-cell { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .danger-zone { border: 1px solid #dc3545; background: #fff5f5; }
        .small-table-preview { font-size: 13px; }

        /* Export page styles */
        .export-table-list {
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 14px;
        }
        .export-option-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 14px 16px;
            background: #f8f9fa;
            transition: border-color 0.15s, background 0.15s;
        }
        .export-option-card:has(input:checked) {
            border-color: #0d6efd;
            background: #eef4ff;
        }
        .export-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            border: 1px solid #dee2e6;
        }
    </style>
</head>
<body>

<div class="page-wrapper">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">Developer Database Manager</h2>
            <p class="text-muted mb-0">Database: <strong><?= h($databaseName); ?></strong></p>
        </div>
        <div class="text-end">
            <span class="badge bg-danger">Development Tool</span>
            <p class="text-muted small mb-0">Remove or protect before production.</p>
        </div>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($successMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= h($errorMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Sidebar -->
        <div class="col-lg-3">
            <div class="table-card sidebar-card">
                <h5 class="fw-bold mb-3">Tables</h5>

                <a href="database.php" class="table-name-link <?= ($selectedTable === "" && !in_array($actionMode, ["create_table","sql","export"])) ? "active" : ""; ?>">
                    Overview
                </a>

                <a href="database.php?mode=create_table" class="table-name-link <?= $actionMode === 'create_table' ? 'active' : ''; ?>" style="color:#198754;font-weight:600;">
                    + Create Table
                </a>

                <a href="database.php?mode=sql" class="table-name-link <?= $actionMode === 'sql' ? 'active' : ''; ?>" style="color:#0d6efd;font-weight:600;">
                    &#9654; SQL Runner
                </a>

                <a href="database.php?mode=export" class="table-name-link <?= $actionMode === 'export' ? 'active' : ''; ?>" style="color:#6f42c1;font-weight:600;">
                    &#8659; Export SQL
                </a>

                <hr class="my-2">

                <?php foreach ($tables as $table): ?>
                    <a href="database.php?table=<?= urlencode($table); ?>" class="table-name-link <?= $selectedTable === $table ? "active" : ""; ?>">
                        <?= h($table); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9">

            <?php if ($actionMode === "export" && $selectedTable === ""): ?>

                <!-- ================================================ -->
                <!-- Export SQL Page                                   -->
                <!-- ================================================ -->

                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <h4 class="fw-bold mb-0">&#8659; Export Database as SQL</h4>
                            <p class="text-muted small mb-0">
                                Generate a <code>.sql</code> dump file you can use to back up or migrate your database.
                            </p>
                        </div>
                        <a href="database.php" class="btn btn-sm btn-outline-secondary">← Back</a>
                    </div>
                </div>

                <form method="POST" action="database.php" id="exportForm">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                    <input type="hidden" name="export_sql_action" value="1">

                    <!-- Table Selection -->
                    <div class="table-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0">Select Tables to Export</h6>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-xs btn-sm btn-outline-secondary" id="selectAllBtn">Select All</button>
                                <button type="button" class="btn btn-xs btn-sm btn-outline-secondary" id="deselectAllBtn">Deselect All</button>
                            </div>
                        </div>

                        <div class="export-table-list" id="tableCheckboxList">
                            <?php foreach ($tables as $table): ?>
                                <?php
                                try {
                                    $rowCount = getTableRowCount($pdo, $table);
                                } catch (PDOException $e) {
                                    $rowCount = 0;
                                }
                                ?>
                                <div class="form-check py-1">
                                    <input
                                        class="form-check-input export-table-cb"
                                        type="checkbox"
                                        name="export_tables[]"
                                        value="<?= h($table); ?>"
                                        id="et_<?= h($table); ?>"
                                        checked>
                                    <label class="form-check-label d-flex justify-content-between align-items-center w-100" for="et_<?= h($table); ?>">
                                        <span class="fw-semibold"><?= h($table); ?></span>
                                        <span class="badge bg-secondary ms-2"><?= number_format($rowCount); ?> rows</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($tables)): ?>
                                <p class="text-muted small mb-0">No tables found in this database.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Export Options -->
                    <div class="table-card">
                        <h6 class="fw-bold mb-3">Export Options</h6>
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="export-option-card d-block cursor-pointer" style="cursor:pointer;">
                                    <div class="d-flex align-items-start gap-3">
                                        <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" name="include_drop" id="opt_drop" checked>
                                        <div>
                                            <div class="fw-semibold">Include DROP TABLE</div>
                                            <div class="text-muted small">
                                                Adds <code>DROP TABLE IF EXISTS</code> before each <code>CREATE TABLE</code>.
                                                Safe for re-importing into an existing database.
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <div class="col-md-6">
                                <label class="export-option-card d-block cursor-pointer" style="cursor:pointer;">
                                    <div class="d-flex align-items-start gap-3">
                                        <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" name="include_data" id="opt_data" checked>
                                        <div>
                                            <div class="fw-semibold">Include Table Data</div>
                                            <div class="text-muted small">
                                                Exports all rows as <code>INSERT INTO</code> statements.
                                                Uncheck to export structure only (schema dump).
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>

                        </div>
                    </div>

                    <!-- Summary & Submit -->
                    <div class="table-card">
                        <div class="export-summary mb-3" id="exportSummary">
                            <span id="summaryText">Loading summary...</span>
                        </div>

                        <?php if (!empty($tables)): ?>
                            <button type="submit" class="btn btn-purple btn-primary" style="background:#6f42c1;border-color:#6f42c1;">
                                &#8659; Download .sql File
                            </button>
                            <span class="text-muted small ms-2">File will be named: <code><?= h($databaseName); ?>_export_YYYYMMDD_HHiiss.sql</code></span>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">No tables to export.</div>
                        <?php endif; ?>
                    </div>

                </form>

            <?php elseif ($actionMode === "sql" && $selectedTable === ""): ?>

                <!-- ================================================ -->
                <!-- SQL Runner Page                                   -->
                <!-- ================================================ -->

                <?php
                $sqlResults = $_SESSION["db_manager_sql_results"] ?? null;
                $lastSql    = $_SESSION["db_manager_last_sql"] ?? "";
                if ($sqlResults) unset($_SESSION["db_manager_sql_results"]);
                if ($sqlResults) unset($_SESSION["db_manager_last_sql"]);
                ?>

                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h4 class="fw-bold mb-0">SQL Runner</h4>
                            <p class="text-muted small mb-0">Execute any SQL query directly against the database.</p>
                        </div>
                        <a href="database.php" class="btn btn-sm btn-outline-secondary">← Back</a>
                    </div>

                    <form method="POST" action="database.php">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                        <input type="hidden" name="sql_executor_action" value="1">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">SQL Query</label>
                            <textarea
                                name="sql_query"
                                id="sqlQueryInput"
                                class="form-control font-monospace"
                                rows="6"
                                placeholder="SELECT * FROM users;"
                                spellcheck="false"
                                required><?= h($lastSql); ?></textarea>
                            <small class="text-muted">Supports SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, SHOW, DESCRIBE, and more.</small>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">&#9654; Run Query</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('sqlQueryInput').value = ''">Clear</button>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <h6 class="fw-bold mb-2">Quick Examples</h6>
                    <div class="row g-2">
                        <?php
                        $examples = [
                            "SHOW TABLES"                               => "List all tables",
                            "DESCRIBE {table}"                          => "Show table structure",
                            "SELECT * FROM {table} LIMIT 10"            => "Preview rows",
                            "SELECT COUNT(*) FROM {table}"              => "Count rows",
                            "ALTER TABLE {table} ADD {col} VARCHAR(255)"=> "Add a column",
                            "TRUNCATE TABLE {table}"                    => "Empty a table",
                        ];
                        ?>
                        <?php foreach ($examples as $sql => $label): ?>
                            <div class="col-md-6">
                                <button type="button" class="btn btn-light border w-100 text-start py-2 px-3 sql-example-btn" data-sql="<?= h($sql); ?>" style="font-size:13px;">
                                    <span class="text-muted d-block" style="font-size:11px;"><?= h($label); ?></span>
                                    <code><?= h($sql); ?></code>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($sqlResults): ?>
                    <div class="table-card">
                        <h6 class="fw-bold mb-2">Result</h6>
                        <pre class="bg-light rounded p-3 mb-3" style="font-size:13px;white-space:pre-wrap;"><?= h($sqlResults["sql"]); ?></pre>

                        <?php if ($sqlResults["type"] === "error"): ?>
                            <div class="alert alert-danger mb-0"><strong>Error:</strong> <?= h($sqlResults["error"]); ?></div>
                        <?php elseif ($sqlResults["type"] === "exec"): ?>
                            <div class="alert alert-success mb-0">Query executed successfully. <strong><?= $sqlResults["affected"]; ?> row(s) affected.</strong></div>
                        <?php elseif ($sqlResults["type"] === "select"): ?>
                            <?php if (empty($sqlResults["rows"])): ?>
                                <div class="alert alert-info mb-0">Query returned no rows.</div>
                            <?php else: ?>
                                <p class="text-muted small mb-2"><?= number_format($sqlResults["count"]); ?> row(s) returned.</p>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm table-hover small-table-preview mb-0">
                                        <thead class="table-dark">
                                            <tr>
                                                <?php foreach (array_keys($sqlResults["rows"][0]) as $col): ?>
                                                    <th><?= h($col); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sqlResults["rows"] as $row): ?>
                                                <tr>
                                                    <?php foreach ($row as $val): ?>
                                                        <td class="value-cell">
                                                            <?= $val === null ? '<span class="text-muted fst-italic">NULL</span>' : h($val); ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($actionMode === "create_table" && $selectedTable === ""): ?>

                <!-- ================================================ -->
                <!-- Create Table Page                                 -->
                <!-- ================================================ -->

                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="fw-bold mb-0">Create New Table</h4>
                        <a href="database.php" class="btn btn-sm btn-outline-secondary">← Back</a>
                    </div>
                    <p class="text-muted small">
                        An <code>id</code> (AUTO_INCREMENT PRIMARY KEY) and <code>created_at</code> (TIMESTAMP) column are added automatically.
                    </p>

                    <form method="POST" action="database.php" id="createTableForm">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                        <input type="hidden" name="create_table_action" value="1">

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Table Name</label>
                            <input type="text" name="new_table_name" class="form-control" placeholder="e.g. products" pattern="[a-zA-Z_][a-zA-Z0-9_]*" required>
                            <small class="text-muted">Letters, numbers, and underscores only.</small>
                        </div>

                        <h6 class="fw-bold mb-3">Columns</h6>
                        <div id="columns-container">
                            <div class="row g-2 align-items-center mb-2 column-row">
                                <div class="col-md-3">
                                    <input type="text" name="col_name[]" class="form-control form-control-sm" placeholder="Column name" pattern="[a-zA-Z_][a-zA-Z0-9_]*">
                                </div>
                                <div class="col-md-3">
                                    <select name="col_type[]" class="form-select form-select-sm">
                                        <option value="VARCHAR(255)">VARCHAR(255)</option>
                                        <option value="TEXT">TEXT</option>
                                        <option value="INT">INT</option>
                                        <option value="BIGINT">BIGINT</option>
                                        <option value="DECIMAL(10,2)">DECIMAL(10,2)</option>
                                        <option value="FLOAT">FLOAT</option>
                                        <option value="BOOLEAN">BOOLEAN</option>
                                        <option value="DATE">DATE</option>
                                        <option value="DATETIME">DATETIME</option>
                                        <option value="TIMESTAMP">TIMESTAMP</option>
                                        <option value="ENUM('active','inactive')">ENUM(active,inactive)</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" name="col_default[]" class="form-control form-control-sm" placeholder="Default (optional)">
                                </div>
                                <div class="col-md-2 d-flex align-items-center gap-2">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="col_null[0]" id="col_null_0">
                                        <label class="form-check-label small" for="col_null_0">Nullable</label>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-col-btn">Remove</button>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-secondary mb-4" id="addColumnBtn">+ Add Column</button>
                        <br>
                        <button type="submit" class="btn btn-success">Create Table</button>
                    </form>
                </div>

            <?php elseif ($selectedTable === ""): ?>

                <!-- ================================================ -->
                <!-- Overview Page                                     -->
                <!-- ================================================ -->

                <div class="table-card">
                    <h4 class="fw-bold mb-2">Database Overview</h4>
                    <p class="text-muted">
                        This section shows all tables, their columns, and a small preview of values.
                        Select a table from the left to perform CRUD operations.
                    </p>
                </div>

                <?php foreach ($tables as $table): ?>
                    <?php
                    try {
                        $ovColumns    = getColumns($pdo, $table);
                        $ovPrimaryKey = getPrimaryKeyColumn($ovColumns);
                        $ovRows       = getRows($pdo, $table, 5, 0, $ovPrimaryKey);
                        $ovRowCount   = getTableRowCount($pdo, $table);
                    } catch (PDOException $e) {
                        $ovColumns  = [];
                        $ovRows     = [];
                        $ovRowCount = 0;
                    }
                    ?>
                    <div class="table-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="fw-bold mb-0">
                                <a href="database.php?table=<?= urlencode($table); ?>" class="text-decoration-none text-dark"><?= h($table); ?></a>
                            </h5>
                            <span class="badge bg-secondary"><?= number_format($ovRowCount); ?> rows</span>
                        </div>
                        <div class="mb-2">
                            <?php foreach ($ovColumns as $col): ?>
                                <span class="badge bg-light text-dark border code-badge me-1 mb-1">
                                    <?= h($col["Field"]); ?>
                                    <span class="text-muted"><?= h($col["Type"]); ?></span>
                                    <?php if (($col["Key"] ?? "") === "PRI"): ?><span class="text-warning">PK</span><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($ovRows) && !empty($ovColumns)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered small-table-preview mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <?php foreach ($ovColumns as $col): ?>
                                                <th><?= h($col["Field"]); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ovRows as $row): ?>
                                            <tr>
                                                <?php foreach ($ovColumns as $col): ?>
                                                    <td class="value-cell">
                                                        <?php
                                                        $val = $row[$col["Field"]] ?? null;
                                                        echo $val === null ? '<span class="text-muted fst-italic">NULL</span>' : h($val);
                                                        ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No rows found.</p>
                        <?php endif; ?>
                        <div class="mt-2">
                            <a href="database.php?table=<?= urlencode($table); ?>" class="btn btn-sm btn-outline-dark">Manage Table →</a>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($actionMode === "insert"): ?>

                <!-- ================================================ -->
                <!-- Insert Form                                       -->
                <!-- ================================================ -->

                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="fw-bold mb-0">Insert into <span class="text-primary"><?= h($selectedTable); ?></span></h4>
                        <a href="database.php?table=<?= urlencode($selectedTable); ?>" class="btn btn-sm btn-outline-secondary">← Back</a>
                    </div>

                    <?php if (empty($selectedEditableColumns)): ?>
                        <div class="alert alert-warning">No editable columns found for this table.</div>
                    <?php else: ?>
                        <form method="POST" action="database.php">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                            <input type="hidden" name="crud_action" value="insert">
                            <input type="hidden" name="table" value="<?= h($selectedTable); ?>">
                            <?php foreach ($selectedEditableColumns as $column): ?>
                                <?php renderColumnInput($column, ""); ?>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-success">Insert Record</button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php elseif ($actionMode === "edit"): ?>

                <!-- ================================================ -->
                <!-- Edit Form                                         -->
                <!-- ================================================ -->

                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="fw-bold mb-0">Edit record in <span class="text-primary"><?= h($selectedTable); ?></span></h4>
                        <a href="database.php?table=<?= urlencode($selectedTable); ?>" class="btn btn-sm btn-outline-secondary">← Back</a>
                    </div>

                    <?php if (!$selectedPrimaryKey): ?>
                        <div class="alert alert-warning">This table does not have a single primary key. Editing is disabled.</div>
                    <?php elseif (!$editRow): ?>
                        <div class="alert alert-danger">Record not found.</div>
                    <?php else: ?>
                        <form method="POST" action="database.php">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                            <input type="hidden" name="crud_action" value="update">
                            <input type="hidden" name="table" value="<?= h($selectedTable); ?>">
                            <input type="hidden" name="primary_value" value="<?= h($editRow[$selectedPrimaryKey]); ?>">
                            <?php foreach ($selectedEditableColumns as $column): ?>
                                <?php
                                $field = $column["Field"];
                                $value = $editRow[$field] ?? "";
                                renderColumnInput($column, $value);
                                ?>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-primary">Update Record</button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <!-- ================================================ -->
                <!-- Table View (default)                              -->
                <!-- ================================================ -->

                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h4 class="fw-bold mb-0"><?= h($selectedTable); ?></h4>
                            <small class="text-muted"><?= number_format($totalRows); ?> total rows</small>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="database.php?mode=export&preselect=<?= urlencode($selectedTable); ?>" class="btn btn-sm btn-outline-secondary" title="Export this table as SQL">
                                &#8659; Export Table
                            </a>
                            <a href="database.php?table=<?= urlencode($selectedTable); ?>&mode=insert" class="btn btn-success btn-sm">
                                + Insert Row
                            </a>
                        </div>
                    </div>

                    <div class="mb-3">
                        <?php foreach ($selectedColumns as $col): ?>
                            <span class="badge bg-light text-dark border code-badge me-1 mb-1">
                                <?= h($col["Field"]); ?>
                                <span class="text-muted"><?= h($col["Type"]); ?></span>
                                <?php if (($col["Key"] ?? "") === "PRI"): ?><span class="text-warning">PK</span><?php endif; ?>
                                <?php if (($col["Null"] ?? "NO") === "YES"): ?><span class="text-info">NULL</span><?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($selectedRows)): ?>
                        <div class="alert alert-info mb-0">This table has no records yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle small-table-preview">
                                <thead class="table-dark">
                                    <tr>
                                        <?php foreach ($selectedColumns as $col): ?>
                                            <th><?= h($col["Field"]); ?></th>
                                        <?php endforeach; ?>
                                        <?php if ($selectedPrimaryKey): ?><th style="width:120px;">Actions</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selectedRows as $row): ?>
                                        <tr>
                                            <?php foreach ($selectedColumns as $col): ?>
                                                <td class="value-cell">
                                                    <?php
                                                    $val = $row[$col["Field"]] ?? null;
                                                    echo $val === null ? '<span class="text-muted fst-italic">NULL</span>' : h($val);
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <?php if ($selectedPrimaryKey): ?>
                                                <td>
                                                    <a href="database.php?table=<?= urlencode($selectedTable); ?>&mode=edit&id=<?= urlencode($row[$selectedPrimaryKey]); ?>" class="btn btn-xs btn-outline-primary btn-sm me-1">Edit</a>
                                                    <form method="POST" action="database.php" style="display:inline;" onsubmit="return confirm('Delete this record? This cannot be undone.');">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                                                        <input type="hidden" name="crud_action" value="delete">
                                                        <input type="hidden" name="table" value="<?= h($selectedTable); ?>">
                                                        <input type="hidden" name="primary_value" value="<?= h($row[$selectedPrimaryKey]); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalRows > 100): ?>
                            <p class="text-muted small mt-2 mb-0">Showing first 100 of <?= number_format($totalRows); ?> rows.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Danger Zone -->
                <div class="table-card danger-zone">
                    <h6 class="fw-bold text-danger mb-2">Danger Zone</h6>
                    <?php if ($selectedPrimaryKey): ?>
                        <p class="text-muted small mb-3">Deleting records is permanent and cannot be undone. Use with caution.</p>
                        <hr class="border-danger opacity-25">
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="fw-semibold text-danger mb-0">Delete This Table</p>
                            <p class="text-muted small mb-0">Permanently drops <strong><?= h($selectedTable); ?></strong> and all its data. This cannot be undone.</p>
                        </div>
                        <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#dropTableModal">
                            Delete Table
                        </button>
                    </div>
                </div>

                <!-- Drop Table Modal -->
                <div class="modal fade" id="dropTableModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header border-danger">
                                <h5 class="modal-title text-danger fw-bold">Delete Table</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>You are about to permanently delete the table <strong><?= h($selectedTable); ?></strong> and <strong>all of its data</strong>.</p>
                                <p class="text-muted small">To confirm, type the table name below:</p>
                                <form method="POST" action="database.php">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                                    <input type="hidden" name="drop_table_action" value="1">
                                    <input type="hidden" name="drop_table_name" value="<?= h($selectedTable); ?>">
                                    <input type="text" name="drop_confirm" class="form-control mb-3" placeholder="<?= h($selectedTable); ?>" autocomplete="off" required>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-danger">Yes, Delete Table</button>
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div><!-- /.col-lg-9 -->
    </div><!-- /.row -->
</div><!-- /.page-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    // -----------------------------------------------
    // Create Table: add/remove column rows
    // -----------------------------------------------
    let colIndex = 1;
    const container = document.getElementById("columns-container");
    const addBtn    = document.getElementById("addColumnBtn");

    if (addBtn) {
        addBtn.addEventListener("click", function() {
            const row = document.createElement("div");
            row.className = "row g-2 align-items-center mb-2 column-row";
            row.innerHTML = `
                <div class="col-md-3">
                    <input type="text" name="col_name[]" class="form-control form-control-sm" placeholder="Column name" pattern="[a-zA-Z_][a-zA-Z0-9_]*">
                </div>
                <div class="col-md-3">
                    <select name="col_type[]" class="form-select form-select-sm">
                        <option value="VARCHAR(255)">VARCHAR(255)</option>
                        <option value="TEXT">TEXT</option>
                        <option value="INT">INT</option>
                        <option value="BIGINT">BIGINT</option>
                        <option value="DECIMAL(10,2)">DECIMAL(10,2)</option>
                        <option value="FLOAT">FLOAT</option>
                        <option value="BOOLEAN">BOOLEAN</option>
                        <option value="DATE">DATE</option>
                        <option value="DATETIME">DATETIME</option>
                        <option value="TIMESTAMP">TIMESTAMP</option>
                        <option value="ENUM('active','inactive')">ENUM(active,inactive)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="col_default[]" class="form-control form-control-sm" placeholder="Default (optional)">
                </div>
                <div class="col-md-2 d-flex align-items-center gap-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="col_null[${colIndex}]" id="col_null_${colIndex}">
                        <label class="form-check-label small" for="col_null_${colIndex}">Nullable</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-col-btn">Remove</button>
                </div>
            `;
            container.appendChild(row);
            colIndex++;
        });

        document.addEventListener("click", function(e) {
            if (e.target.classList.contains("remove-col-btn")) {
                e.target.closest(".column-row").remove();
            }
        });
    }

    // -----------------------------------------------
    // SQL Runner: quick example buttons & Ctrl+Enter
    // -----------------------------------------------
    document.querySelectorAll(".sql-example-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var sql = this.getAttribute("data-sql");
            var textarea = document.getElementById("sqlQueryInput");
            if (textarea) { textarea.value = sql; textarea.focus(); }
        });
    });

    var sqlInput = document.getElementById("sqlQueryInput");
    if (sqlInput) {
        sqlInput.addEventListener("keydown", function(e) {
            if (e.ctrlKey && e.key === "Enter") { this.closest("form").submit(); }
        });
    }

    // -----------------------------------------------
    // Export Page: Select All / Deselect All + summary
    // -----------------------------------------------
    const selectAllBtn   = document.getElementById("selectAllBtn");
    const deselectAllBtn = document.getElementById("deselectAllBtn");
    const summaryEl      = document.getElementById("exportSummary");
    const summaryText    = document.getElementById("summaryText");

    function updateExportSummary() {
        if (!summaryText) return;
        const checkboxes = document.querySelectorAll(".export-table-cb");
        const checked    = document.querySelectorAll(".export-table-cb:checked");
        const includeData = document.getElementById("opt_data");
        const includeDrop = document.getElementById("opt_drop");

        const tableWord = checked.length === 1 ? "table" : "tables";
        let parts = [checked.length + " " + tableWord + " selected (out of " + checkboxes.length + ")"];

        if (includeDrop && includeDrop.checked) parts.push("DROP TABLE statements included");
        if (includeData && includeData.checked) {
            parts.push("structure + data");
        } else {
            parts.push("structure only (no INSERT data)");
        }

        summaryText.textContent = parts.join(" · ");
        summaryEl.style.borderColor = checked.length === 0 ? "#dc3545" : "#dee2e6";
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener("click", function() {
            document.querySelectorAll(".export-table-cb").forEach(cb => cb.checked = true);
            updateExportSummary();
        });
    }

    if (deselectAllBtn) {
        deselectAllBtn.addEventListener("click", function() {
            document.querySelectorAll(".export-table-cb").forEach(cb => cb.checked = false);
            updateExportSummary();
        });
    }

    document.querySelectorAll(".export-table-cb, #opt_data, #opt_drop").forEach(function(el) {
        el.addEventListener("change", updateExportSummary);
    });

    // Handle preselect query param: when coming from "Export Table" button on a table page
    const urlParams = new URLSearchParams(window.location.search);
    const preselect = urlParams.get("preselect");
    if (preselect) {
        document.querySelectorAll(".export-table-cb").forEach(cb => {
            cb.checked = (cb.value === preselect);
        });
    }

    // Run once on load
    updateExportSummary();
})();
</script>

</body>
</html>