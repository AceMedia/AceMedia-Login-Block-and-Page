document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    document.querySelectorAll('[data-show-password]').forEach(function(span) {
        span.addEventListener('click', function() {
            const input = span.previousElementSibling;
            if (input.type === 'password') {
                input.type = 'text';
                span.textContent = 'Hide Password';
            } else {
                input.type = 'password';
                span.textContent = 'Show Password';
            }
        });
    });

    // Handle login button click
    const loginButton = document.querySelector('.wp-block-acemedia-login-block form .wp-block-button__link');
    if (loginButton) {
        loginButton.addEventListener('click', function(event) {
            event.preventDefault();
            const form = loginButton.closest('form');
            if (form) {
                form.submit();
            }
        });
    }

    // Set form action and add nonce
    const loginForm = document.querySelector('.wp-block-login-form form');
    if (loginForm) {
        // Set the form action dynamically
        loginForm.action = aceLoginBlock.loginUrl;


        // Ensure the redirect_to field is present and has the correct value
        let redirectInput = loginForm.querySelector('input[name="redirect_to"]');
        if (!redirectInput) {
            redirectInput = document.createElement('input');
            redirectInput.type = 'hidden';
            redirectInput.name = 'redirect_to';
            loginForm.appendChild(redirectInput);
        }
        redirectInput.value = aceLoginBlock.redirectUrl || '/wp-admin';
    }
});