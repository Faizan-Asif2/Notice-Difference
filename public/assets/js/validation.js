document.addEventListener('DOMContentLoaded', function () {
  // Show / hide password buttons
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle'));
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // Sign-up: live checklist and validation
  var form = document.querySelector('form[data-validate="register"]');
  if (form) {
    var box = document.getElementById('js-errors');
    var lenRule = document.querySelector('[data-rule="len"]');
    var matchRule = document.querySelector('[data-rule="match"]');

    function updateChecks() {
      lenRule.classList.toggle('ok', form.password.value.length >= 8);
      matchRule.classList.toggle('ok',
        form.password.value !== '' && form.password.value === form.confirm_password.value);
    }
    form.password.addEventListener('input', updateChecks);
    form.confirm_password.addEventListener('input', updateChecks);

    form.addEventListener('submit', function (event) {
      var problems = [];
      if (form.full_name.value.trim().length < 2) problems.push('Enter your full name (at least 2 characters).');
      var emailVal = form.email ? form.email.value.trim() : '';
      var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailVal || !emailRegex.test(emailVal)) problems.push('Enter a valid email address.');
      if (form.password.value.length < 8) problems.push('Password must be at least 8 characters.');
      if (form.password.value !== form.confirm_password.value) problems.push('Passwords do not match.');
      if (problems.length > 0) {
        event.preventDefault();
        box.textContent = problems.join(' ');
        box.style.display = 'block';
      } else {
        box.style.display = 'none';
      }
    });
  }

  // Loading state: stop double submits and show progress
  document.querySelectorAll('form[data-loading]').forEach(function (f) {
    f.addEventListener('submit', function (event) {
      if (event.defaultPrevented) return;
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = f.getAttribute('data-loading'); }
    });
  });
});