<?php
$toast = null;
if (isset($_SESSION["success"])) {
    $toast = ['kind' => 'ok', 'icon' => 'bi-check-lg', 'msg' => $_SESSION["success"]];
    unset($_SESSION["success"]);
} elseif (isset($_SESSION["error"])) {
    $toast = ['kind' => 'danger', 'icon' => 'bi-x-lg', 'msg' => $_SESSION["error"]];
    unset($_SESSION["error"]);
}
?>
    </div> <!-- End main content -->

    <?php if ($toast): ?>
    <div class="yz-toast yz-toast-<?= $toast['kind']; ?>" id="yzToast">
        <div class="icon"><i class="bi <?= $toast['icon']; ?>"></i></div>
        <div class="msg"><?= htmlspecialchars($toast['msg']); ?></div>
    </div>
    <script>
        setTimeout(function () {
            var t = document.getElementById('yzToast');
            if (t) t.remove();
        }, 3200);
    </script>
    <?php endif; ?>

</div> <!-- End admin wrapper -->

<!-- ===== Shared Confirm Modal (replaces native confirm()) ===== -->
<div class="yz-modal-overlay" id="confirmModal">
    <div class="yz-modal yz-modal-sm" style="padding:28px; text-align:center;">
        <div id="confirmIcon" style="width:60px; height:60px; margin:0 auto 16px; border-radius:50%; background:var(--danger-soft); color:var(--danger); display:flex; align-items:center; justify-content:center; font-size:28px;">🗑</div>
        <h3 id="confirmTitle" style="font-size:19px; margin:0;">Delete this record?</h3>
        <p id="confirmMessage" style="font-size:13.5px; color:var(--text-muted); margin-top:8px; line-height:1.5;">This action can't be undone.</p>
        <div style="display:flex; gap:10px; margin-top:24px;">
            <button type="button" class="btn-yz btn-yz-outline" style="flex:1; justify-content:center;" onclick="closeYzModal('confirmModal')">Cancel</button>
            <button type="button" class="btn-yz btn-yz-danger" id="confirmActionBtn" style="flex:1; justify-content:center;">Delete</button>
        </div>
    </div>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<script src="../assets/js/admin.js"></script>

<!-- jQuery + Select2 (only used for .select2-enable selects with many options) -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // ===== Mobile sidebar drawer =====
    function toggleMobileSidebar() {
        var sidebar = document.getElementById('adminSidebar');
        var overlay = document.getElementById('mobileSidebarOverlay');
        var open = sidebar.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('show', open);
        document.body.style.overflow = open ? 'hidden' : '';
    }
    function closeMobileSidebar() {
        var sidebar = document.getElementById('adminSidebar');
        var overlay = document.getElementById('mobileSidebarOverlay');
        sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    }
    // Close drawer when a nav link is tapped on mobile
    document.querySelectorAll('#adminSidebar .sidebar-menu a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 900) closeMobileSidebar();
        });
    });

    // ===== Foldable sidebar =====
    function toggleSidebarFold() {
        const sidebar = document.getElementById('adminSidebar');
        const main = document.querySelector('.admin-main');
        const collapsed = sidebar.classList.toggle('collapsed');
        if (main) main.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem('yzSidebarCollapsed', collapsed ? '1' : '0');
    }
    (function () {
        if (localStorage.getItem('yzSidebarCollapsed') === '1') {
            const sidebar = document.getElementById('adminSidebar');
            const main = document.querySelector('.admin-main');
            if (sidebar) sidebar.classList.add('collapsed');
            if (main) main.classList.add('sidebar-collapsed');
        }
    })();

    // Initializes (or re-initializes) Select2 on a select with class "select2-enable".
    // IMPORTANT: must only be called while the select is actually visible (modal already
    // shown) — Select2 reads getBoundingClientRect() at init time, so initializing it on a
    // display:none ancestor produces a zero/garbage width and a mispositioned dropdown
    // (the "options hidden behind the blur" and "numbers outside the parent" bugs).
    // The dropdown is parented to the select's .yz-modal-overlay (not the inner scrollable
    // .yz-modal box) so it can never be clipped by the modal's own overflow-y:auto, and it
    // inherits that overlay's z-index so it always renders above the backdrop.
    function yzSelect2(el) {
        var $el = $(el);
        if ($el.data('select2')) $el.select2('destroy');
        var overlay = $el.closest('.yz-modal-overlay');
        $el.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: $el.data('placeholder') || 'Search…',
            allowClear: false,
            dropdownParent: overlay.length ? overlay : $(document.body),
        });
    }
    function yzInitSelect2In(container) {
        if (!window.jQuery || !container) return;
        container.querySelectorAll('select.select2-enable').forEach(function (el) { yzSelect2(el); });
    }

    // ===== Password show/hide toggle =====
    const YZ_EYE_ICON = '<svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
    const YZ_EYE_OFF_ICON = '<svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 4.22-5.07M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    function toggleYzPassword(btn, inputId) {
        const input = document.getElementById(inputId);
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        btn.innerHTML = showing ? YZ_EYE_ICON : YZ_EYE_OFF_ICON;
    }

    // ===== Youzarsif modal helper =====
    function openYzModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.add('show');
        // Select2 widgets inside this modal must be (re)initialized now that it's visible.
        yzInitSelect2In(el);
    }
    function closeYzModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('show');
    }
    document.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('yz-modal-overlay')) {
            e.target.classList.remove('show');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.yz-modal-overlay.show').forEach(function (el) {
                el.classList.remove('show');
            });
        }
    });

    // ===== Shared confirm modal (replaces native confirm()) =====
    // Usage: <form onsubmit="return confirmAction(this, { title:'...', message:'...', confirmLabel:'Delete', danger:true });">
    let __yzConfirmForm = null;
    function confirmAction(form, opts) {
        opts = opts || {};
        __yzConfirmForm = form;
        document.getElementById('confirmTitle').textContent = opts.title || 'Delete this record?';
        document.getElementById('confirmMessage').textContent = opts.message || "This action can't be undone.";
        const btn = document.getElementById('confirmActionBtn');
        btn.textContent = opts.confirmLabel || 'Delete';
        const danger = opts.danger !== false;
        btn.className = 'btn-yz ' + (danger ? 'btn-yz-danger' : 'btn-yz-dark');
        btn.style.flex = '1'; btn.style.justifyContent = 'center';
        const icon = document.getElementById('confirmIcon');
        icon.textContent = opts.icon || (danger ? '🗑' : '⊘');
        icon.style.background = danger ? 'var(--danger-soft)' : 'var(--warn-soft)';
        icon.style.color = danger ? 'var(--danger)' : 'var(--warn)';
        openYzModal('confirmModal');
        return false;
    }
    document.getElementById('confirmActionBtn').addEventListener('click', function () {
        if (__yzConfirmForm) {
            closeYzModal('confirmModal');
            const formToSubmit = __yzConfirmForm;
            __yzConfirmForm = null;
            formToSubmit.submit();
        }
    });
</script>

</body>

</html>
