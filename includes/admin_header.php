<?php
require_once "../config/app.php";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?= APP_SHORT_NAME; ?> | Admin Dashboard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()); ?>">

    <!-- Bootstrap CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <!-- Custom Admin CSS -->
    <link rel="stylesheet" href="../assets/css/admin.css?v=4">

    <!-- Select2 (used only on selects with many options, scoped via .select2-enable) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <script>
        // Forces a fresh server check when this page is restored from the
        // browser's back/forward cache — Cache-Control headers alone don't
        // always stop bfcache, so without this, "back" after logout could
        // show the stale authenticated page instead of redirecting to login.
        window.addEventListener("pageshow", function (event) {
            if (event.persisted) window.location.reload();
        });
    </script>
</head>

<body>

    <!-- Mobile overlay backdrop -->
    <div class="mobile-sidebar-overlay" id="mobileSidebarOverlay" onclick="closeMobileSidebar()"></div>

    <!-- Mobile top bar (burger + mini logo) -->
    <div class="mobile-topbar">
        <button type="button" class="mobile-burger" onclick="toggleMobileSidebar()" aria-label="Open menu">
            <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <img src="../assets/img/mainlogo.png" alt="Youzarsif" class="mobile-topbar-logo">
    </div>

    <div class="admin-wrapper">