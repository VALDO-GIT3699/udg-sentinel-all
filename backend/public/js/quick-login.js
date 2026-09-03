(function () {
    var toggle = document.getElementById('toggle-password');
    var passwordInput = document.getElementById('password');
    var eyeOpen = document.getElementById('icon-eye-open');
    var eyeClosed = document.getElementById('icon-eye-closed');

    if (toggle && passwordInput) {
        toggle.addEventListener('click', function () {
            var isVisible = passwordInput.getAttribute('type') === 'text';
            passwordInput.setAttribute('type', isVisible ? 'password' : 'text');
            toggle.setAttribute('aria-pressed', String(!isVisible));
            toggle.setAttribute('aria-label', isVisible ? 'Mostrar contraseña' : 'Ocultar contraseña');
            eyeOpen.classList.toggle('hidden', !isVisible);
            eyeClosed.classList.toggle('hidden', isVisible);
        });
    }

    var form = document.getElementById('sentinel-login-form');
    var submitButton = document.getElementById('submit-button');

    if (form && submitButton) {
        form.addEventListener('submit', function () {
            // Evita doble envío / clics repetidos mientras el servidor procesa
            // el intento; el formulario ya viaja con la petición en curso.
            submitButton.disabled = true;
            submitButton.textContent = 'Verificando...';
        });
    }
})();
