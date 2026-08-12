console.log("Youzarsif Sweets Admin Dashboard Loaded");

// Stamps every form on the page with the CSRF token issued to this session
// (see <meta name="csrf-token"> in admin_header.php), so actions/*.php's
// validateCsrf() check passes without having to hand-edit every form.
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;
    var token = meta.getAttribute('content');
    document.querySelectorAll('form').forEach(function (form) {
        if (form.querySelector('input[name="csrf_token"]')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = token;
        form.appendChild(input);
    });
})();