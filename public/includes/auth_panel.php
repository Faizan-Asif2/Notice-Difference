<aside class="auth-hero">
  <div class="hero-top">
    <a class="brand" href="<?= BASE_URL ?>/"><?= logoMark() ?><span>NoticeDiff</span></a>
    <a class="back-link" href="<?= BASE_URL ?>/">&larr; Back to website</a>
  </div>
  <div>
    <h2>Every Change. Clearly Communicated.</h2>
    <p>See every lecture change, the reason behind it, and whether you were told in time.</p>
  </div>
  <div class="diff" role="img" aria-label="Example notice: Tuesday lecture cancelled with 26 hours notice">
    <p class="diff-tag">Example notice (not real data)</p>
    <p class="diff-title">Automata Theory</p>
    <p class="diff-sub">Tuesday lecture</p>
    <div class="diff-row del"><span class="sign">&minus;</span><span class="text">10:00 AM, Room 12</span></div>
    <div class="diff-row add"><span class="sign">+</span><span>Cancelled</span></div>
    <p class="diff-meta">Reason: instructor unavailable. Notice given 26 hours ahead.</p>
  </div>
</aside>