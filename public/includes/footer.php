<?php $layout = $layout ?? 'public'; ?>
</main>
<?php if ($layout === 'public'): ?>
<footer class="pub-footer">
  <div class="pf-grid">
    <div>
      <a class="brand" href="<?= BASE_URL ?>/"><?= logoMark() ?><span>NoticeDiff</span></a>
      <p class="pf-tag">Every Change. Clearly Communicated.</p>
      <p class="pf-desc">A lecture change and notification system that records what changed, when, and whether students were told in time.</p>
    </div>
    <div>
      <h3>Explore</h3>
      <ul>
        <li><a href="<?= BASE_URL ?>/">Home</a></li>
        <li><a href="<?= BASE_URL ?>/#features">Features</a></li>
        <li><a href="<?= BASE_URL ?>/#how-it-works">How it works</a></li>
        <li><a href="<?= BASE_URL ?>/#about">About</a></li>
      </ul>
    </div>
    <div>
      <h3>Account</h3>
      <ul>
        <li><a href="<?= BASE_URL ?>/auth/login.php">Log in</a></li>
        <li><a href="<?= BASE_URL ?>/auth/register.php">Sign up</a></li>
      </ul>
    </div>
  </div>
  <div class="pf-bottom">&copy; <?= date('Y') ?> NoticeDiff. A university project.</div>
</footer>
<?php elseif ($layout === 'app'): ?>
    <footer class="app-footer">
      <span>&copy; <?= date('Y') ?> NoticeDiff</span>
      <span>Every Change. Clearly Communicated.</span>
    </footer>
  </div>
</div>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/validation.js?v=<?= @filemtime(__DIR__ . '/../assets/js/validation.js') ?: 1 ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/nav.js?v=<?= @filemtime(__DIR__ . '/../assets/js/nav.js') ?: 1 ?>"></script>
</body>
</html>