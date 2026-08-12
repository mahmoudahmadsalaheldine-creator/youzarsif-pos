<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);

try {
    $stores = $pdo->query("SELECT location_id, location_name FROM locations WHERE status = 'active' AND location_type = 'store' ORDER BY location_name ASC")->fetchAll();
} catch (PDOException $e) {
    $stores = [];
}

try {
    $rateRow = $pdo->query("SELECT usd_to_lbp FROM exchange_rates ORDER BY created_at DESC LIMIT 1")->fetch();
    $exchangeRate = $rateRow ? (float) $rateRow['usd_to_lbp'] : 89000;
} catch (PDOException $e) {
    $exchangeRate = 89000;
}

try {
    $products = $pdo->query("
        SELECT fp.product_id, fp.product_name, fp.product_code, fp.selling_price_usd, u.abbreviation, c.category_name
        FROM finished_products fp
        INNER JOIN units u ON fp.unit_id = u.unit_id
        INNER JOIN categories c ON fp.category_id = c.category_id
        WHERE fp.status = 'active'
        ORDER BY fp.product_name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

function getProductStockPos($pdo, $productId, $locationId)
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END), 0) AS s
        FROM product_stock_movements WHERE product_id = :p AND location_id = :l
    ");
    $stmt->execute([':p' => $productId, ':l' => $locationId]);
    $row = $stmt->fetch();
    return $row ? (float) $row['s'] : 0;
}

$stockMap = [];
foreach ($products as $p) {
    foreach ($stores as $s) {
        $stockMap[$p['product_id']][$s['location_id']] = getProductStockPos($pdo, $p['product_id'], $s['location_id']);
    }
}

$catColors = ['#c8924b', '#b5683c', '#9a4f56', '#a06a2c', '#8a7b4a', '#5d7a6b'];
$catColorMap = [];
$ci = 0;
foreach ($products as $p) {
    if (!isset($catColorMap[$p['category_name']])) {
        $catColorMap[$p['category_name']] = $catColors[$ci % count($catColors)];
        $ci++;
    }
}

$lastSale = $_SESSION["last_sale"] ?? null;
if ($lastSale) unset($_SESSION["last_sale"]);

$toast = null;
if (isset($_SESSION["success"])) { $toast = ['kind' => 'ok', 'msg' => $_SESSION["success"]]; unset($_SESSION["success"]); }
elseif (isset($_SESSION["error"])) { $toast = ['kind' => 'danger', 'msg' => $_SESSION["error"]]; unset($_SESSION["error"]); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= APP_SHORT_NAME; ?> | Touch POS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { background: var(--bg); }
        .pos-root { display: flex; height: 100vh; width: 100%; overflow: hidden; }
        /* Rail is always a hidden fixed drawer (all screen sizes) */
        .pos-rail {
            position: fixed; top: 0; left: 0; bottom: 0; width: 240px;
            background: var(--chocolate); color: var(--cream);
            display: flex; flex-direction: column; align-items: stretch;
            padding: 0; gap: 0;
            transform: translateX(-100%);
            transition: transform 0.25s ease;
            z-index: 1100; overflow-y: auto; overflow-x: hidden;
        }
        .pos-rail.mobile-open { transform: translateX(0); }
        .pos-rail-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 1099;
        }
        .pos-rail-overlay.show { display: block; }
        .pos-rail-logo-wrap { padding: 22px 20px 18px; border-bottom: 1px solid rgba(248,241,232,0.1); }
        .pos-rail-logo-wrap img { max-width: 160px; height: auto; object-fit: contain; }
        .pos-rail-icon {
            width: 100%; height: auto; border-radius: 0;
            display: flex; align-items: center; justify-content: flex-start;
            padding: 12px 22px; gap: 14px; font-size: 18px;
            text-decoration: none; color: var(--cream); cursor: pointer;
        }
        .pos-rail-icon:hover { background: rgba(248,241,232,.08); }
        .pos-rail-icon-label { font-family: var(--font-body); font-size: 13.5px; font-weight: 600; }
        .pos-rail-section { padding: 14px 22px 4px; font-size: 10px; font-weight: 600; letter-spacing: .08em; opacity: .45; text-transform: uppercase; }
        .pos-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .pos-topbar { flex: none; display: flex; align-items: center; gap: 16px; padding: 16px 24px; background: var(--cream); border-bottom: 1px solid var(--border-soft); }
        .pos-cat-tab { flex: none; border: 1px solid var(--border-input); border-radius: 9999px; padding: 9px 18px; font-family: var(--font-body); font-weight: 600; font-size: 13.5px; cursor: pointer; white-space: nowrap; background: #fff; color: var(--text); }
        .pos-cat-tab.active { background: var(--caramel); color: #fff; border-color: var(--caramel); }
        .pos-card { position: relative; display: flex; flex-direction: column; gap: 8px; text-align: left; background: #fff; border: 1px solid var(--border-soft); border-radius: 18px; padding: 10px; cursor: pointer; transition: transform .14s ease, box-shadow .14s ease; box-shadow: 0 2px 8px rgba(59,36,22,.04); }
        .pos-card:hover { transform: translateY(-3px); box-shadow: 0 12px 24px rgba(59,36,22,.12); }
        .pos-card.flash { animation: posFlash .7s ease-out; }
        @keyframes posFlash { 0% { box-shadow: 0 0 0 0 rgba(47,106,63,.55); } 100% { box-shadow: 0 0 0 14px rgba(47,106,63,0); } }
        .pos-cart-panel { width: 462px; flex: none; background: #fff; border-left: 1px solid var(--border-soft); display: flex; flex-direction: column; box-shadow: -6px 0 24px rgba(59,36,22,.05); }
        #cartResizer:hover, #cartResizer.dragging { background: var(--bg) !important; }
        #cartResizer:hover > div { background: var(--caramel) !important; }
        #panelResizer:hover > div, #panelResizer.dragging > div { background: var(--caramel) !important; }
        .seg-btn { border: 1px solid var(--border-input); border-radius: 9999px; padding: 6px 13px; font-family: var(--font-body); font-weight: 600; font-size: 12.5px; cursor: pointer; background: #fff; color: var(--text); }
        .seg-btn.active { background: var(--chocolate); color: var(--cream); border-color: var(--chocolate); }
        .chip-btn { flex: none; border: 1px solid rgba(59,36,22,.12); background: #fff; border-radius: 9999px; padding: 5px 11px; font-family: var(--font-body); font-weight: 600; font-size: 12px; color: var(--text); cursor: pointer; }
        .chip-btn:hover { background: var(--bg); }
        @media print {
            body * { visibility: hidden; }
            [data-receipt], [data-receipt] * { visibility: visible; }
            [data-receipt] { position: fixed; inset: 0 0 auto 0; margin: 0 auto; box-shadow: none !important; border-radius: 0 !important; }
        }
    </style>
    <!-- These overrides must come after the unconditional .pos-rail/.pos-root/etc.
         rules above — same specificity, later wins, so without this the page's
         own <style> block always beat admin.css's responsive rules, even on
         narrow screens (the rail stayed a fixed 78px vertical column). -->
    <style>
        /* pos-main always fills full width (rail is always fixed/out of flow) */
        .pos-main { flex: 1; min-width: 0; display: flex; flex-direction: column; width: 100%; }
        @media (max-width: 900px) {
            .pos-root { flex-direction: column; height: auto; min-height: 100vh; overflow: visible; }
            .pos-topbar { flex-wrap: wrap; padding: 12px 14px; gap: 10px; }
            .pos-cart-panel { width: 100% !important; border-left: none; border-top: 1px solid var(--border-soft); }
        }
        @media (max-width: 600px) {
            .pos-cart-panel { height: auto !important; }
            .pos-card { padding: 8px !important; }
        }
    </style>
    <script>
        window.addEventListener("pageshow", function (event) { if (event.persisted) window.location.reload(); });
    </script>
</head>
<body>

<div class="pos-rail-overlay" id="posRailOverlay" onclick="closePosRail()"></div>

<div class="pos-root">

    <aside class="pos-rail" id="posRail">
        <div class="pos-rail-logo-wrap">
            <img src="../assets/img/mainlogo.png" alt="Youzarsif">
        </div>
        <div class="pos-rail-section">Navigation</div>
        <a href="dashboard.php" class="pos-rail-icon"><span>◫</span><span class="pos-rail-icon-label">Dashboard</span></a>
        <a href="items.php" class="pos-rail-icon"><span>▦</span><span class="pos-rail-icon-label">Ingredients & Items</span></a>
        <a href="production-batches.php" class="pos-rail-icon"><span>⚙</span><span class="pos-rail-icon-label">Production</span></a>
        <a href="factory-to-store.php" class="pos-rail-icon"><span>⇄</span><span class="pos-rail-icon-label">Factory to Store</span></a>
        <a href="expenses.php" class="pos-rail-icon"><span>＄</span><span class="pos-rail-icon-label">Expenses</span></a>
        <a href="reports.php" class="pos-rail-icon"><span>◰</span><span class="pos-rail-icon-label">Reports</span></a>
        <div style="flex:1;"></div>
        <div style="padding:16px 22px; border-top:1px solid rgba(248,241,232,0.1); display:flex; align-items:center; gap:12px;">
            <div style="width:36px; height:36px; flex:none; border-radius:50%; background:#5a3a23; display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:700; font-size:14px;">A</div>
            <div style="font-size:13px; font-weight:600; opacity:.75;">Admin</div>
        </div>
    </aside>

    <main class="pos-main">
        <header class="pos-topbar">
            <button onclick="togglePosRail()" style="width:40px; height:40px; flex:none; border:none; border-radius:50%; background:var(--bg); color:var(--text); font-size:17px; cursor:pointer;">☰</button>
            <div style="display:flex; flex-direction:column; gap:2px;">
                <div style="font-family:var(--font-display); font-weight:800; font-size:19px; line-height:1;">Touch POS</div>
                <div style="font-size:12px; color:var(--text-faint);">Cashier · Admin</div>
            </div>

            <div style="display:flex; align-items:center; gap:9px; margin-left:8px; background:var(--bg); padding:7px 8px 7px 14px; border-radius:9999px;">
                <span style="font-size:12px; color:var(--text-faint); font-weight:600;">Store</span>
                <select id="storeSelect" style="border:none; background:#fff; border-radius:9999px; padding:6px 12px; font-family:var(--font-body); font-weight:600; font-size:13px; color:var(--text); cursor:pointer;">
                    <option value="">Select store</option>
                    <?php foreach ($stores as $s): ?>
                        <option value="<?= (int) $s['location_id']; ?>"><?= htmlspecialchars($s['location_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="spacer"></div>

            <div style="display:flex; align-items:center; gap:8px; background:var(--bg); padding:7px 14px; border-radius:9999px;">
                <span class="rate-chip-dot"></span>
                <span style="font-size:12px; color:var(--text-faint); font-weight:600;">USD→LBP</span>
                <input type="number" id="rateInput" value="<?= $exchangeRate; ?>" style="width:86px; border:none; background:#fff; border-radius:9999px; padding:6px 10px; font-family:var(--font-display); font-weight:700; font-size:13px; color:var(--text); text-align:right;">
                <span style="font-size:12px; color:var(--text-faint);">LL</span>
            </div>

            <button type="button" id="fullScreenBtn" onclick="toggleFullScreen()" title="Full screen" style="width:40px; height:40px; flex:none; border:none; border-radius:50%; background:var(--bg); color:var(--text); font-size:16px; cursor:pointer;">⛶</button>
        </header>

        <div style="flex:none; padding:18px 24px 8px;">
            <div style="display:flex; align-items:center; gap:12px; background:#fff; border:1px solid var(--border-soft); border-radius:9999px; padding:12px 20px; box-shadow:0 2px 10px rgba(59,36,22,.04);">
                <span style="font-size:18px; color:var(--caramel);">⌕</span>
                <input id="productSearch" placeholder="Search sweets, or scan a barcode + Enter…" autocomplete="off" style="flex:1; border:none; outline:none; font-family:var(--font-body); font-size:15px; color:var(--text); background:transparent;">
                <span style="font-size:11px; color:#b49a78; font-weight:600; border:1px dashed var(--border-input); padding:4px 10px; border-radius:9999px;">⎘ Scanner ready</span>
            </div>
        </div>

        <div class="pos-scroll" style="flex:none; display:flex; gap:9px; padding:10px 24px 14px; overflow-x:auto;" id="categoryTabs">
            <button type="button" class="pos-cat-tab active" data-cat="all" onclick="filterByCategory('all')">All</button>
            <?php foreach ($catColorMap as $catName => $color): ?>
                <button type="button" class="pos-cat-tab" data-cat="<?= htmlspecialchars($catName); ?>" onclick="filterByCategory('<?= htmlspecialchars(addslashes($catName)); ?>')"><?= htmlspecialchars($catName); ?></button>
            <?php endforeach; ?>
        </div>

        <div class="pos-scroll" style="flex:1; overflow-y:auto; padding:4px 24px 24px;">
            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:14px;" id="productGrid">
                <?php foreach ($products as $p): ?>
                    <?php
                    $color = $catColorMap[$p['category_name']];
                    $priceLbp = $p['selling_price_usd'] * $exchangeRate;
                    ?>
                    <button type="button" class="pos-card" id="card-<?= (int) $p['product_id']; ?>"
                        data-id="<?= (int) $p['product_id']; ?>" data-name="<?= htmlspecialchars($p['product_name']); ?>"
                        data-code="<?= htmlspecialchars($p['product_code']); ?>" data-price="<?= $p['selling_price_usd']; ?>"
                        data-unit="<?= htmlspecialchars($p['abbreviation']); ?>" data-cat="<?= htmlspecialchars($p['category_name']); ?>"
                        onclick="addToCart(<?= (int) $p['product_id']; ?>)">
                        <div style="height:56px; border-radius:12px; background:<?= $color; ?>22; display:flex; align-items:center; justify-content:center; position:relative; overflow:hidden;">
                            <div style="position:absolute; inset:0; opacity:.16; background:repeating-linear-gradient(135deg,<?= $color; ?> 0 7px,transparent 7px 14px);"></div>
                            <span style="font-family:var(--font-display); font-weight:800; font-size:22px; color:<?= $color; ?>; position:relative;"><?= htmlspecialchars(mb_substr($p['product_name'], 0, 1)); ?></span>
                        </div>
                        <div style="text-align:left; flex:1; display:flex; flex-direction:column; gap:2px;">
                            <div style="font-family:var(--font-display); font-weight:700; font-size:14px; line-height:1.2; color:var(--text);"><?= htmlspecialchars($p['product_name']); ?></div>
                        </div>
                        <div style="display:flex; align-items:baseline; justify-content:space-between; gap:6px; width:100%;">
                            <span style="font-family:var(--font-display); font-weight:800; font-size:16px; color:var(--text);">$<?= number_format($p['selling_price_usd'], 2); ?></span>
                            <span class="pos-price-lbp" style="font-size:10.5px; color:var(--text-faint);"><?= number_format($priceLbp, 0); ?> LL</span>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php if (empty($products)): ?>
                <div style="text-align:center; padding:60px 0; color:#b49a78; font-size:15px;">No products available.</div>
            <?php endif; ?>
            <div id="noResults" style="display:none; text-align:center; padding:60px 0; color:#b49a78; font-size:15px;"></div>
        </div>
    </main>

    <div id="panelResizer" title="Drag to resize" style="flex:none; width:10px; cursor:col-resize; display:flex; align-items:center; justify-content:center; background:var(--bg); border-left:1px solid var(--border-soft); border-right:1px solid var(--border-soft);">
        <div style="width:4px; height:36px; border-radius:9999px; background:var(--border-input);"></div>
    </div>

    <aside class="pos-cart-panel">
        <div id="cartTopSection" style="display:flex; flex-direction:column; flex:0 0 auto; height:52%; min-height:140px;">
            <div style="flex:none; display:flex; align-items:center; justify-content:space-between; padding:18px 22px 14px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-family:var(--font-display); font-weight:800; font-size:18px;">Current Order</span>
                    <span id="itemCount" style="background:var(--chocolate); color:var(--cream); font-family:var(--font-display); font-weight:700; font-size:12px; min-width:24px; height:24px; padding:0 7px; border-radius:9999px; display:inline-flex; align-items:center; justify-content:center;">0</span>
                </div>
                <div style="display:flex; align-items:center; gap:16px;">
                    <button type="button" onclick="openAllOrdersModal()" style="border:none; background:transparent; color:var(--text-muted); font-family:var(--font-body); font-weight:600; font-size:13px; cursor:pointer;">▤ All Orders</button>
                    <button type="button" onclick="clearCart()" style="border:none; background:transparent; color:var(--danger); font-family:var(--font-body); font-weight:600; font-size:13px; cursor:pointer;">Clear</button>
                </div>
            </div>

            <div class="pos-scroll" style="flex:1; overflow-y:auto; padding:0 22px;" id="cartLines">
                <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:12px; color:#c3ab8c; text-align:center; padding:40px 20px;" id="emptyCartMsg">
                    <div style="width:64px; height:64px; border-radius:20px; background:#f3ebdd; display:flex; align-items:center; justify-content:center; font-size:30px;">🧺</div>
                    <div style="font-family:var(--font-display); font-weight:700; font-size:15px; color:var(--text-muted);">No items yet</div>
                    <div style="font-size:13px; max-width:220px;">Tap a sweet or scan a barcode to start the order.</div>
                </div>
            </div>
        </div>

        <div id="cartResizer" title="Drag to resize" style="flex:none; height:12px; cursor:row-resize; display:flex; align-items:center; justify-content:center; background:var(--soft-bg); border-top:1px solid var(--border-soft); border-bottom:1px solid var(--border-soft);">
            <div style="width:36px; height:4px; border-radius:9999px; background:var(--border-input);"></div>
        </div>

        <div id="cartBottomSection" class="pos-scroll" style="flex:1; overflow-y:auto; background:var(--soft-bg); padding:16px 22px 20px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;" id="discountSeg">
                <span style="font-size:12px; color:var(--text-faint); font-weight:600; margin-right:2px;">Discount</span>
                <button type="button" class="seg-btn active" data-type="none" onclick="setDiscountType('none')">None</button>
                <button type="button" class="seg-btn" data-type="percentage" onclick="setDiscountType('percentage')">%</button>
                <button type="button" class="seg-btn" data-type="fixed" onclick="setDiscountType('fixed')">$</button>
                <input type="number" id="discountValue" oninput="renderTotals()" style="display:none; width:64px; margin-left:auto; border:1px solid var(--border-input); background:#fff; border-radius:9px; padding:6px 9px; font-family:var(--font-display); font-weight:700; font-size:13px; text-align:right;">
            </div>

            <div style="display:flex; justify-content:space-between; font-size:13px; color:var(--text-muted); margin-bottom:5px;"><span>Subtotal</span><span id="subtotalStr" style="font-weight:600;">$0.00</span></div>
            <div id="discountRow" style="display:none; justify-content:space-between; font-size:13px; color:var(--danger); margin-bottom:5px;"><span id="discountLabel">Discount</span><span id="discountAmountStr" style="font-weight:600;">−$0.00</span></div>

            <div style="display:flex; align-items:flex-end; justify-content:space-between; margin:10px 0 14px; padding-top:12px; border-top:1px dashed var(--border-input);">
                <div>
                    <div style="font-size:12px; color:var(--text-faint); font-weight:600; text-transform:uppercase; letter-spacing:.06em;">Total</div>
                    <div id="totalUsdStr" style="font-family:var(--font-display); font-weight:800; font-size:34px; line-height:1; color:var(--text);">$0.00</div>
                </div>
                <div style="text-align:right; background:var(--chocolate); color:var(--cream); padding:8px 14px; border-radius:14px;">
                    <div style="font-size:10px; opacity:.6; font-weight:600; letter-spacing:.06em;">LEBANESE POUNDS</div>
                    <div id="totalLbpStr" style="font-family:var(--font-display); font-weight:800; font-size:18px; line-height:1.2;">0 LL</div>
                </div>
            </div>

            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:10px;">
                <div>
                    <div style="font-size:11px; color:var(--text-faint); font-weight:600; margin-bottom:4px;">Cash USD</div>
                    <input type="number" id="paidUsd" value="0" oninput="renderTotals()" class="no-spinner" style="width:100%; min-width:0; border:1px solid var(--border-input); background:#fff; border-radius:11px; padding:9px 10px; font-family:var(--font-display); font-weight:700; font-size:14px; text-align:right;">
                    <div class="pos-scroll" style="display:flex; gap:5px; margin-top:6px; overflow-x:auto;">
                        <?php foreach ([1, 5, 10, 20, 50, 100] as $v): ?>
                            <button type="button" class="chip-btn" onclick="addUsd(<?= $v; ?>)">$<?= $v; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:11px; color:var(--text-faint); font-weight:600; margin-bottom:4px;">Cash LBP</div>
                    <input type="number" id="paidLbp" value="0" oninput="renderTotals()" class="no-spinner" style="width:100%; min-width:0; border:1px solid var(--border-input); background:#fff; border-radius:11px; padding:9px 10px; font-family:var(--font-display); font-weight:700; font-size:13px; text-align:right;">
                    <div class="pos-scroll" style="display:flex; gap:5px; margin-top:6px; overflow-x:auto;">
                        <?php foreach ([['v' => 100000, 'l' => '100K'], ['v' => 200000, 'l' => '200K'], ['v' => 500000, 'l' => '500K'], ['v' => 1000000, 'l' => '1M'], ['v' => 2000000, 'l' => '2M']] as $c): ?>
                            <button type="button" class="chip-btn" onclick="addLbp(<?= $c['v']; ?>)"><?= $c['l']; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:8px; margin-bottom:12px;">
                <button type="button" onclick="setExact()" style="flex:1; border:1px solid var(--border-input); background:#fff; border-radius:9999px; padding:9px; font-family:var(--font-body); font-weight:600; font-size:13px; color:var(--text); cursor:pointer;">Exact amount</button>
                <button type="button" onclick="resetPaid()" style="flex:none; border:1px solid var(--border-input); background:#fff; border-radius:9999px; padding:9px 16px; font-family:var(--font-body); font-weight:600; font-size:13px; color:var(--text-muted); cursor:pointer;">Reset</button>
            </div>

            <div id="changeBox" style="display:flex; align-items:center; justify-content:space-between; background:#f6ece0; border-radius:13px; padding:10px 16px; margin-bottom:14px;">
                <span id="changeLabel" style="font-size:13px; font-weight:600; color:var(--text-faint);">Balance due</span>
                <div style="text-align:right;">
                    <div id="changeUsdStr" style="font-family:var(--font-display); font-weight:800; font-size:20px; line-height:1; color:var(--text-faint);">$0.00</div>
                    <div id="changeLbpStr" style="font-size:11px; color:var(--text-faint); opacity:.75;">0 LL</div>
                </div>
            </div>

            <button type="button" id="chargeBtn" onclick="charge()" style="width:100%; border:none; border-radius:9999px; padding:16px; font-family:var(--font-display); font-weight:800; font-size:17px; cursor:not-allowed; background:#d9cbb6; color:#a08a6c;">Add items to charge</button>
        </div>
    </aside>

</div>

<!-- Receipt Modal -->
<div class="yz-modal-overlay" id="receiptModal" style="z-index:2000;" data-modal-bg="1">
    <div data-receipt="1" class="yz-modal yz-modal-sm" style="padding:26px 26px 22px; text-align:left;" id="receiptContent"></div>
</div>

<!-- All Orders Modal -->
<div class="yz-modal-overlay" id="allOrdersModal" style="z-index:2000;" data-modal-bg="1">
    <div class="yz-modal" style="padding:0;">
        <div class="yz-modal-header">
            <div>
                <h3>Current Order</h3>
                <p>All items in this cart</p>
            </div>
            <button type="button" class="yz-modal-close" onclick="closeYzModal('allOrdersModal')">✕</button>
        </div>
        <div class="yz-modal-body" id="allOrdersList" style="max-height:60vh; overflow-y:auto;"></div>
        <div class="yz-modal-footer">
            <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('allOrdersModal')">Close</button>
        </div>
    </div>
</div>

<?php if ($toast): ?>
<div class="yz-toast yz-toast-<?= $toast['kind']; ?>" id="yzToast" style="z-index:2100;">
    <div class="icon"><?= $toast['kind'] === 'ok' ? '✓' : '✕'; ?></div>
    <div class="msg"><?= htmlspecialchars($toast['msg']); ?></div>
</div>
<script>setTimeout(function () { var t = document.getElementById('yzToast'); if (t) t.remove(); }, 3200);</script>
<?php endif; ?>

<form id="saleForm" method="POST" action="<?= BASE_URL; ?>actions/pos_action.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">
    <input type="hidden" name="action" value="complete_sale">
    <input type="hidden" name="location_id" id="formLocationId">
    <input type="hidden" name="exchange_rate" id="formExchangeRate">
    <input type="hidden" name="paid_usd" id="formPaidUsd">
    <input type="hidden" name="paid_lbp" id="formPaidLbp">
    <input type="hidden" name="discount_type" id="formDiscountType">
    <input type="hidden" name="discount_value" id="formDiscountValue">
    <input type="hidden" name="notes" id="formNotes" value="">
    <input type="hidden" name="cart" id="formCart">
</form>

<form id="voidForm" method="POST" action="<?= BASE_URL; ?>actions/pos_action.php" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">
    <input type="hidden" name="action" value="void_sale">
    <input type="hidden" name="sale_id" id="voidSaleId">
    <input type="hidden" name="void_reason" id="voidReason">
</form>

<script>
    window.POS_DATA = {
        stockMap: <?= json_encode($stockMap); ?>,
        baseRate: <?= $exchangeRate; ?>,
        lastSale: <?= json_encode($lastSale); ?>
    };
</script>
<script src="../assets/js/pos.js?v=2"></script>

</body>
</html>
