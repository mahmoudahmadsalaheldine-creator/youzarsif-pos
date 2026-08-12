// Touch POS (Factory) — logic extracted from admin/pos.php.
// Expects a window.POS_DATA = { stockMap, baseRate, lastSale } object,
// injected by the page before this file is loaded.
(function () {
    const stockMap = window.POS_DATA.stockMap;
    const baseRate = window.POS_DATA.baseRate;
    let rate = baseRate;
    let storeId = '';
    let activeCategory = 'all';
    let cart = {};
    let discountType = 'none';
    let railVisible = true;

    window.toggleRail = function () {
        railVisible = !railVisible;
        document.getElementById('posRail').style.display = railVisible ? 'flex' : 'none';
    };

    window.togglePosRail = function () {
        var rail = document.getElementById('posRail');
        var overlay = document.getElementById('posRailOverlay');
        var open = rail.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('show', open);
        document.body.style.overflow = open ? 'hidden' : '';
    };

    window.closePosRail = function () {
        var rail = document.getElementById('posRail');
        var overlay = document.getElementById('posRailOverlay');
        rail.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    };

    // Close drawer when a nav link is tapped on mobile
    document.querySelectorAll('#posRail .pos-rail-icon').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 900) window.closePosRail();
        });
    });

    window.toggleFullScreen = function () {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(function () {});
        } else {
            document.exitFullscreen().catch(function () {});
        }
    };
    document.addEventListener('fullscreenchange', function () {
        const btn = document.getElementById('fullScreenBtn');
        btn.textContent = document.fullscreenElement ? '⛶' : '⛶';
        btn.title = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
        btn.style.background = document.fullscreenElement ? 'var(--caramel)' : 'var(--bg)';
        btn.style.color = document.fullscreenElement ? '#fff' : 'var(--text)';
    });

    // Design-system toast, replaces the native browser alert() popup.
    function yzAlert(message, kind) {
        kind = kind || 'warn';
        const existing = document.getElementById('yzAlertToast');
        if (existing) existing.remove();
        const div = document.createElement('div');
        div.id = 'yzAlertToast';
        div.className = 'yz-toast yz-toast-' + kind;
        div.style.zIndex = '3000';
        div.innerHTML = '<div class="icon">' + (kind === 'danger' ? '✕' : '⚠') + '</div><div class="msg">' + message + '</div>';
        document.body.appendChild(div);
        setTimeout(function () { div.remove(); }, 3200);
    }

    function fmtUsd(n) { return '$' + (Number(n) || 0).toFixed(2); }
    function fmtLbp(n) { return Math.round(Number(n) || 0).toLocaleString('en-US') + ' LL'; }

    document.getElementById('storeSelect').addEventListener('change', function () {
        storeId = this.value;
        cart = {};
        renderCart();
        updateStockUi();
    });

    document.getElementById('rateInput').addEventListener('input', function () {
        rate = parseFloat(this.value) || baseRate;
        document.querySelectorAll('.pos-card').forEach(function (card) {
            const price = parseFloat(card.getAttribute('data-price'));
            card.querySelector('.pos-price-lbp').textContent = fmtLbp(price * rate);
        });
        renderTotals();
    });

    function getStock(productId) {
        return (stockMap[productId] && stockMap[productId][storeId]) ? stockMap[productId][storeId] : 0;
    }

    function updateStockUi() {
        document.querySelectorAll('.pos-card').forEach(function (card) {
            const pid = card.getAttribute('data-id');
            const stock = getStock(pid);
            card.style.opacity = (storeId && stock <= 0) ? '.4' : '1';
        });
    }

    window.filterByCategory = function (cat) {
        activeCategory = cat;
        document.querySelectorAll('.pos-cat-tab').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-cat') === cat); });
        applyFilters();
    };

    document.getElementById('productSearch').addEventListener('input', function () { applyFilters(); });
    document.getElementById('productSearch').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const q = this.value.trim().toLowerCase();
        if (!q) return;
        let hit = null;
        document.querySelectorAll('.pos-card').forEach(function (card) {
            if (card.getAttribute('data-code').toLowerCase() === q || card.getAttribute('data-name').toLowerCase() === q) hit = card;
        });
        if (!hit) {
            document.querySelectorAll('.pos-card').forEach(function (card) {
                if (!hit && (card.getAttribute('data-code').toLowerCase().includes(q) || card.getAttribute('data-name').toLowerCase().includes(q))) hit = card;
            });
        }
        if (hit) {
            if (!storeId) { yzAlert('Please select a store first.', 'warn'); return; }
            addToCart(parseInt(hit.getAttribute('data-id')));
            hit.classList.add('flash');
            setTimeout(function () { hit.classList.remove('flash'); }, 700);
            this.value = '';
        }
    });

    function applyFilters() {
        const q = document.getElementById('productSearch').value.trim().toLowerCase();
        let visibleCount = 0;
        document.querySelectorAll('.pos-card').forEach(function (card) {
            const name = card.getAttribute('data-name').toLowerCase();
            const code = card.getAttribute('data-code').toLowerCase();
            const cat = card.getAttribute('data-cat');
            const catMatch = activeCategory === 'all' || cat === activeCategory;
            const searchMatch = !q || name.includes(q) || code.includes(q);
            const show = catMatch && searchMatch;
            card.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        const noResults = document.getElementById('noResults');
        if (q && visibleCount === 0) {
            noResults.style.display = '';
            noResults.textContent = 'No sweets match "' + q + '".';
        } else {
            noResults.style.display = 'none';
        }
    }

    function addToCart(productId) {
        if (!storeId) { yzAlert('Please select a store first.', 'warn'); return; }
        const stock = getStock(productId);
        if (stock <= 0) { yzAlert('This product is out of stock at this store.', 'warn'); return; }
        if (cart[productId]) {
            if (cart[productId].qty >= stock) { yzAlert('Cannot add more. Only ' + stock + ' available.', 'warn'); return; }
            cart[productId].qty += 1;
        } else {
            const card = document.getElementById('card-' + productId);
            cart[productId] = { id: productId, name: card.getAttribute('data-name'), price: parseFloat(card.getAttribute('data-price')), unit: card.getAttribute('data-unit'), stock, qty: 1 };
        }
        renderCart();
    }
    window.addToCart = addToCart;

    window.inc = function (id) {
        const stock = getStock(id);
        if (cart[id].qty >= stock) { yzAlert('Cannot exceed available stock: ' + stock, 'warn'); return; }
        cart[id].qty += 1;
        renderCart();
    };
    window.dec = function (id) {
        cart[id].qty -= 1;
        if (cart[id].qty <= 0) delete cart[id];
        renderCart();
    };
    window.removeLine = function (id) {
        delete cart[id];
        renderCart();
    };
    window.clearCart = function () {
        cart = {};
        document.getElementById('paidUsd').value = 0;
        document.getElementById('paidLbp').value = 0;
        setDiscountType('none');
        renderCart();
    };

    function emptyCartHtml() {
        return '<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:12px; color:#c3ab8c; text-align:center; padding:40px 20px;"><div style="width:64px; height:64px; border-radius:20px; background:#f3ebdd; display:flex; align-items:center; justify-content:center; font-size:30px;">🧺</div><div style="font-family:var(--font-display); font-weight:700; font-size:15px; color:var(--text-muted);">No items yet</div><div style="font-size:13px; max-width:220px;">Tap a sweet or scan a barcode to start the order.</div></div>';
    }

    function buildCartLinesHtml() {
        const keys = Object.keys(cart);
        let html = '';
        keys.forEach(function (id) {
            const l = cart[id];
            const lineUsd = l.price * l.qty;
            html += '<div style="display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--border-soft);">' +
                '<div style="width:42px; height:42px; flex:none; border-radius:11px; background:var(--bg); display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:800; font-size:16px; color:var(--caramel);">' + l.name.charAt(0) + '</div>' +
                '<div style="flex:1; min-width:0;"><div style="font-family:var(--font-display); font-weight:700; font-size:14px; line-height:1.2;">' + l.name + '</div><div style="font-size:11.5px; color:var(--text-faint);">' + fmtUsd(l.price) + ' each · ' + fmtLbp(lineUsd * rate) + '</div></div>' +
                '<div style="display:flex; align-items:center; gap:6px; flex:none;"><button type="button" onclick="dec(' + id + ')" style="width:30px; height:30px; border:none; border-radius:9px; background:var(--bg); color:var(--text); font-size:18px; line-height:1; cursor:pointer;">−</button><span style="min-width:22px; text-align:center; font-family:var(--font-display); font-weight:700; font-size:15px;">' + l.qty + '</span><button type="button" onclick="inc(' + id + ')" style="width:30px; height:30px; border:none; border-radius:9px; background:var(--bg); color:var(--text); font-size:18px; line-height:1; cursor:pointer;">+</button></div>' +
                '<div style="width:62px; text-align:right; flex:none; font-family:var(--font-display); font-weight:800; font-size:14px;">' + fmtUsd(lineUsd) + '</div>' +
                '<button type="button" onclick="removeLine(' + id + ')" title="Remove" style="border:none; background:transparent; color:var(--danger); font-size:16px; cursor:pointer; flex:none;">🗑</button>' +
                '</div>';
        });
        return html;
    }

    function renderCart() {
        const wrap = document.getElementById('cartLines');
        const keys = Object.keys(cart);
        wrap.innerHTML = keys.length === 0 ? emptyCartHtml() : buildCartLinesHtml();
        if (document.getElementById('allOrdersModal').classList.contains('show')) renderAllOrdersList();
        renderTotals();
    }
    window.renderCart = renderCart;

    function renderAllOrdersList() {
        const list = document.getElementById('allOrdersList');
        const keys = Object.keys(cart);
        list.innerHTML = keys.length === 0 ? emptyCartHtml() : buildCartLinesHtml();
    }

    window.openAllOrdersModal = function () {
        renderAllOrdersList();
        openYzModal('allOrdersModal');
    };

    function setDiscountType(type) {
        discountType = type;
        document.querySelectorAll('#discountSeg .seg-btn').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-type') === type); });
        document.getElementById('discountValue').style.display = type === 'none' ? 'none' : '';
        if (type === 'none') document.getElementById('discountValue').value = '';
        renderTotals();
    }
    window.setDiscountType = setDiscountType;

    window.addUsd = function (v) { document.getElementById('paidUsd').value = (parseFloat(document.getElementById('paidUsd').value) || 0) + v; renderTotals(); };
    window.addLbp = function (v) { document.getElementById('paidLbp').value = (parseFloat(document.getElementById('paidLbp').value) || 0) + v; renderTotals(); };
    window.resetPaid = function () { document.getElementById('paidUsd').value = 0; document.getElementById('paidLbp').value = 0; renderTotals(); };
    window.setExact = function () {
        const t = computeTotals();
        document.getElementById('paidUsd').value = Math.round(t.totalUsd * 100) / 100;
        document.getElementById('paidLbp').value = 0;
        renderTotals();
    };

    function computeTotals() {
        let subtotal = 0;
        Object.values(cart).forEach(function (l) { subtotal += l.price * l.qty; });
        const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;
        let discountAmount = 0;
        if (discountType === 'percentage') discountAmount = subtotal * (discountValue / 100);
        else if (discountType === 'fixed') discountAmount = discountValue;
        discountAmount = Math.min(Math.max(discountAmount, 0), subtotal);
        const totalUsd = subtotal - discountAmount;
        const totalLbp = totalUsd * rate;
        const paidUsd = parseFloat(document.getElementById('paidUsd').value) || 0;
        const paidLbp = parseFloat(document.getElementById('paidLbp').value) || 0;
        const paidTotalUsd = paidUsd + (paidLbp / rate);
        const changeUsd = paidTotalUsd - totalUsd;
        return { subtotal, discountAmount, totalUsd, totalLbp, paidUsd, paidLbp, paidTotalUsd, changeUsd, discountValue };
    }

    function renderTotals() {
        const t = computeTotals();
        const hasCart = Object.keys(cart).length > 0;
        document.getElementById('itemCount').textContent = Object.values(cart).reduce(function (a, l) { return a + l.qty; }, 0);
        document.getElementById('subtotalStr').textContent = fmtUsd(t.subtotal);
        document.getElementById('totalUsdStr').textContent = fmtUsd(t.totalUsd);
        document.getElementById('totalLbpStr').textContent = fmtLbp(t.totalLbp);

        const discountRow = document.getElementById('discountRow');
        if (t.discountAmount > 0) {
            discountRow.style.display = 'flex';
            document.getElementById('discountLabel').textContent = 'Discount' + (discountType === 'percentage' ? ' (' + t.discountValue + '%)' : '');
            document.getElementById('discountAmountStr').textContent = '−' + fmtUsd(t.discountAmount);
        } else {
            discountRow.style.display = 'none';
        }

        const enough = t.paidTotalUsd + 1e-9 >= t.totalUsd && t.totalUsd > 0;
        const changeBox = document.getElementById('changeBox');
        const changeLabel = document.getElementById('changeLabel');
        const changeUsdStr = document.getElementById('changeUsdStr');
        const changeLbpStr = document.getElementById('changeLbpStr');
        if (enough) {
            changeBox.style.background = '#e7f0e9';
            changeLabel.style.color = 'var(--success)'; changeLabel.textContent = 'Change due';
            changeUsdStr.style.color = 'var(--success)'; changeUsdStr.textContent = fmtUsd(t.changeUsd);
            changeLbpStr.style.color = 'var(--success)'; changeLbpStr.textContent = fmtLbp(t.changeUsd * rate);
        } else {
            changeBox.style.background = '#f6ece0';
            changeLabel.style.color = 'var(--text-faint)'; changeLabel.textContent = 'Balance due';
            changeUsdStr.style.color = 'var(--text-faint)'; changeUsdStr.textContent = fmtUsd(t.totalUsd - t.paidTotalUsd);
            changeLbpStr.style.color = 'var(--text-faint)'; changeLbpStr.textContent = fmtLbp((t.totalUsd - t.paidTotalUsd) * rate);
        }

        const chargeBtn = document.getElementById('chargeBtn');
        const canCharge = hasCart && enough;
        chargeBtn.style.cursor = canCharge ? 'pointer' : 'not-allowed';
        chargeBtn.style.background = canCharge ? 'var(--success)' : '#d9cbb6';
        chargeBtn.style.color = canCharge ? '#fff' : '#a08a6c';
        chargeBtn.textContent = !hasCart ? 'Add items to charge' : (enough ? 'Charge ' + fmtUsd(t.totalUsd) : 'Insufficient payment');
    }
    window.renderTotals = renderTotals;

    function renderReceipt(sale) {
        let linesHtml = '';
        sale.items.forEach(function (it) {
            linesHtml += '<div style="display:flex; justify-content:space-between; gap:10px; font-size:13px; margin-bottom:7px;"><span>' + it.quantity + '× ' + it.product_name + '</span><span style="font-family:var(--font-display); font-weight:700; flex:none;">$' + parseFloat(it.total_price_usd).toFixed(2) + '</span></div>';
        });
        const discountHtml = sale.discount_amount_usd > 0
            ? '<div style="display:flex; justify-content:space-between; font-size:13px; color:var(--danger); margin-bottom:4px;"><span>Discount</span><span>−$' + parseFloat(sale.discount_amount_usd).toFixed(2) + '</span></div>' : '';
        document.getElementById('receiptContent').innerHTML =
            '<div style="text-align:center; border-bottom:2px dashed rgba(59,36,22,.18); padding-bottom:16px; margin-bottom:14px;">' +
            '<div style="width:48px; height:48px; margin:0 auto 8px; border-radius:14px; background:var(--caramel); color:var(--chocolate); display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:800; font-size:24px;">Y</div>' +
            '<div style="font-family:var(--font-display); font-weight:800; font-size:18px;">Youzarsif Sweets</div>' +
            '<div style="font-size:12px; color:var(--text-faint); margin-top:4px;">' + sale.location_name + ' · ' + sale.created_at + '</div>' +
            '<div style="font-family:var(--font-display); font-weight:700; font-size:12px; color:var(--text); margin-top:4px;">' + sale.sale_ref + '</div></div>' +
            linesHtml +
            '<div style="border-top:1px solid rgba(59,36,22,.12); margin-top:10px; padding-top:10px;">' +
            '<div style="display:flex; justify-content:space-between; font-size:13px; color:var(--text-muted); margin-bottom:4px;"><span>Subtotal</span><span>$' + parseFloat(sale.subtotal_usd).toFixed(2) + '</span></div>' +
            discountHtml +
            '<div style="display:flex; justify-content:space-between; align-items:baseline; margin-top:6px;"><span style="font-family:var(--font-display); font-weight:800; font-size:15px;">Total</span><span style="font-family:var(--font-display); font-weight:800; font-size:20px;">$' + parseFloat(sale.total_usd).toFixed(2) + '</span></div>' +
            '<div style="text-align:right; font-size:12px; color:var(--text-faint);">' + Math.round(sale.total_lbp).toLocaleString() + ' LL</div></div>' +
            '<div style="background:var(--soft-bg); border-radius:12px; padding:11px 14px; margin-top:14px; font-size:12.5px; color:var(--text-muted);">' +
            '<div style="display:flex; justify-content:space-between; margin-bottom:3px;"><span>Paid (USD)</span><span style="font-weight:600;">$' + parseFloat(sale.paid_usd).toFixed(2) + '</span></div>' +
            '<div style="display:flex; justify-content:space-between; margin-bottom:3px;"><span>Paid (LBP)</span><span style="font-weight:600;">' + Math.round(sale.paid_lbp).toLocaleString() + ' LL</span></div>' +
            '<div style="display:flex; justify-content:space-between; color:var(--success); font-weight:700;"><span>Change</span><span>$' + parseFloat(sale.change_usd).toFixed(2) + ' · ' + Math.round(sale.change_lbp).toLocaleString() + ' LL</span></div></div>' +
            '<div style="text-align:center; font-size:12px; color:var(--text-faint); margin:16px 0 4px;">Thank you — see you again!</div>' +
            '<div style="display:flex; gap:10px; margin-top:14px;">' +
            '<button type="button" onclick="window.print()" style="flex:1; border:1px solid var(--border-input); background:#fff; border-radius:9999px; padding:12px; font-family:var(--font-body); font-weight:600; font-size:14px; color:var(--text); cursor:pointer;">⎙ Print</button>' +
            '<button type="button" onclick="closeYzModal(\'receiptModal\')" style="flex:1; border:none; background:var(--chocolate); color:var(--cream); border-radius:9999px; padding:12px; font-family:var(--font-display); font-weight:700; font-size:14px; cursor:pointer;">New Sale</button>' +
            '</div>';
        openYzModal('receiptModal');
    }

    window.charge = function () {
        if (!storeId) { yzAlert('Please select a store.', 'warn'); return; }
        const t = computeTotals();
        if (!Object.keys(cart).length || t.paidTotalUsd + 1e-9 < t.totalUsd || t.totalUsd <= 0) return;

        const cartArray = Object.values(cart).map(function (l) { return { product_id: l.id, quantity: l.qty }; });
        const chargeBtn = document.getElementById('chargeBtn');
        const chargedCart = cart;
        chargeBtn.style.cursor = 'wait';

        // Submitted via fetch (instead of a real form submit) so the page
        // never reloads — a full navigation always exits Fullscreen mode,
        // and browsers won't let JS silently re-enter it afterwards.
        const body = new URLSearchParams();
        body.set('action', 'complete_sale');
        body.set('ajax', '1');
        body.set('location_id', storeId);
        body.set('exchange_rate', rate);
        body.set('paid_usd', t.paidUsd);
        body.set('paid_lbp', t.paidLbp);
        body.set('discount_type', discountType);
        body.set('discount_value', t.discountValue || 0);
        body.set('cart', JSON.stringify(cartArray));
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) body.set('csrf_token', csrfMeta.getAttribute('content'));

        // Use getAttribute, not the .action property — the form has a hidden
        // input named "action", which shadows HTMLFormElement.action and makes
        // the property return that <input> element instead of the URL string.
        fetch(document.getElementById('saleForm').getAttribute('action'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (res) {
                return res.text().then(function (raw) {
                    let data;
                    try {
                        data = JSON.parse(raw);
                    } catch (e) {
                        // Server didn't return JSON — most likely the session expired
                        // and we got the login page's HTML back, or a PHP warning was
                        // printed before the response. Surface the real cause instead
                        // of a generic "network error".
                        console.error('Charge: non-JSON response from server:', raw.slice(0, 500));
                        if (/<html|login/i.test(raw)) {
                            throw new Error('Your session has expired. Please log in again.');
                        }
                        throw new Error('Unexpected server response (HTTP ' + res.status + '). Check the browser console for details.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (!data.success) {
                    yzAlert(data.message || 'Failed to complete sale.', 'danger');
                    return;
                }
                Object.values(chargedCart).forEach(function (l) {
                    if (stockMap[l.id] && stockMap[l.id][storeId] !== undefined) {
                        stockMap[l.id][storeId] = Math.max(0, stockMap[l.id][storeId] - l.qty);
                    }
                });
                cart = {};
                document.getElementById('paidUsd').value = 0;
                document.getElementById('paidLbp').value = 0;
                setDiscountType('none');
                renderCart();
                updateStockUi();
                renderReceipt(data.sale);
            })
            .catch(function (err) {
                yzAlert(err.message || 'Network error — please check your connection and try again.', 'danger');
            })
            .finally(function () {
                renderTotals();
            });
    };

    window.openYzModal = function (id) { document.getElementById(id).classList.add('show'); };
    window.closeYzModal = function (id) { document.getElementById(id).classList.remove('show'); };
    document.addEventListener('click', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-modal-bg')) e.target.classList.remove('show');
    });

    if (window.POS_DATA.lastSale) {
        renderReceipt(window.POS_DATA.lastSale);
    }

    // ===== Resizable left/right (product area vs cart panel) divider =====
    (function () {
        const resizer = document.getElementById('panelResizer');
        const cartPanel = document.querySelector('.pos-cart-panel');
        const root = document.querySelector('.pos-root');
        let dragging = false, startX = 0, startWidth = 0;

        function onMove(clientX) {
            const delta = startX - clientX; // dragging left grows the cart panel
            const rootWidth = root.getBoundingClientRect().width;
            const minCart = 320;
            const maxCart = rootWidth - 480;
            const newWidth = Math.min(Math.max(startWidth + delta, minCart), maxCart);
            cartPanel.style.width = newWidth + 'px';
        }

        function isStackedLayout() { return window.innerWidth <= 900; }

        resizer.addEventListener('mousedown', function (e) {
            if (isStackedLayout()) return;
            dragging = true;
            startX = e.clientX;
            startWidth = cartPanel.getBoundingClientRect().width;
            document.body.style.userSelect = 'none';
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            onMove(e.clientX);
        });
        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            document.body.style.userSelect = '';
        });

        resizer.addEventListener('touchstart', function (e) {
            if (isStackedLayout()) return;
            dragging = true;
            startX = e.touches[0].clientX;
            startWidth = cartPanel.getBoundingClientRect().width;
        }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (!dragging) return;
            onMove(e.touches[0].clientX);
        }, { passive: true });
        document.addEventListener('touchend', function () { dragging = false; });

        window.addEventListener('resize', function () {
            if (isStackedLayout()) cartPanel.style.width = '';
        });
    })();

    // ===== Resizable cart panel divider =====
    (function () {
        const resizer = document.getElementById('cartResizer');
        const topSection = document.getElementById('cartTopSection');
        const panel = document.querySelector('.pos-cart-panel');
        let dragging = false, startY = 0, startHeight = 0;

        function isStackedLayout() { return window.innerWidth <= 900; }

        function onMove(clientY) {
            const delta = clientY - startY;
            const panelHeight = panel.getBoundingClientRect().height;
            const minTop = 140;
            const minBottom = 260;
            const newHeight = Math.min(Math.max(startHeight + delta, minTop), panelHeight - minBottom);
            topSection.style.height = newHeight + 'px';
        }

        resizer.addEventListener('mousedown', function (e) {
            if (isStackedLayout()) return;
            dragging = true;
            startY = e.clientY;
            startHeight = topSection.getBoundingClientRect().height;
            document.body.style.userSelect = 'none';
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            onMove(e.clientY);
        });
        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            document.body.style.userSelect = '';
        });

        resizer.addEventListener('touchstart', function (e) {
            if (isStackedLayout()) return;
            dragging = true;
            startY = e.touches[0].clientY;
            startHeight = topSection.getBoundingClientRect().height;
        }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (!dragging) return;
            onMove(e.touches[0].clientY);
        }, { passive: true });
        document.addEventListener('touchend', function () { dragging = false; });
        window.addEventListener('resize', function () {
            if (isStackedLayout()) topSection.style.height = '';
        });
    })();

    renderCart();
})();
