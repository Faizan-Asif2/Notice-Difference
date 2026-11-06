<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/home.php');
    exit;
}

$pageTitle = 'Never miss a lecture change';
$bodyClass = 'public-page';
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <div>
    <span class="badge ok" style="margin-bottom: 16px; font-size: 0.82rem; padding: 5px 14px; border-radius: 999px;">✦ Smart Timetable &amp; Change Tracking</span>
    <h1>Never Miss a Lecture Change.</h1>
    <p class="sub">NoticeDiff shows students every approved timetable change, the reason behind it, and whether notice was given in time, so you can plan your day and your commute.</p>
    <div class="cta-row">
      <a class="btn btn-lg" href="<?= BASE_URL ?>/auth/register.php">Get started</a>
      <a class="btn btn-outline btn-lg" href="<?= BASE_URL ?>/auth/login.php">Log in</a>
    </div>
  </div>
  <div class="preview" role="img" aria-label="Preview of the student dashboard with example data">
    <p class="diff-tag">Preview with example data</p>
    <ul class="slots">
      <li class="slot">
        <div class="slot-time">9:00 AM<span>10:30 AM</span></div>
        <div><div class="slot-title">Database Systems</div><div class="meta">Room 4</div></div>
        <span class="badge ok">&#10003; On schedule</span>
      </li>
      <li class="slot">
        <div class="slot-time">11:00 AM<span>12:30 PM</span></div>
        <div><div class="slot-title">Automata Theory</div><div class="meta">Room 12</div></div>
        <span class="badge bad">&#10005; Cancelled</span>
      </li>
    </ul>
    <div class="diff-row del"><span class="sign">&minus;</span><span class="text">Tue, 11:00 AM, Room 12</span></div>
    <div class="diff-row add"><span class="sign">+</span><span>Lecture cancelled</span></div>
    <span class="badge ok">&#10003; Notice given 26 hours ahead</span>
  </div>
</section>

<section class="section alt" id="features">
  <div class="section-in">
    <h2>Everything about a schedule change, in one place</h2>
    <p class="lead">Built around the problem students describe most: finding out too late.</p>
    <div class="feature-grid">
      <div class="card feature"><span class="stat-icon"><?= icon('swap') ?></span><h3>Timetable change tracking</h3><p>The original timetable stays on record. Changes show next to it, never over it.</p></div>
      <div class="card feature"><span class="stat-icon"><?= icon('clock') ?></span><h3>Advance notice monitoring</h3><p>Each request is checked against the 24-hour deadline, and late ones are flagged.</p></div>
      <div class="card feature"><span class="stat-icon"><?= icon('bell') ?></span><h3>Instant in-app notifications</h3><p>Students are notified as soon as a change is approved, with the reason attached.</p></div>
      <div class="card feature"><span class="stat-icon"><?= icon('history') ?></span><h3>Change history</h3><p>Who requested, who approved, and when. Records can be added to but not edited.</p></div>
      <div class="card feature"><span class="stat-icon"><?= icon('bus') ?></span><h3>Commute-aware warnings</h3><p>Students can note their bus times and see when a change may clash with them.</p></div>
    </div>
  </div>
</section>

<section class="section" id="how-it-works">
  <div class="section-in">
    <h2>How it works</h2>
    <p class="lead">Three steps from request to notification.</p>
    <ol class="steps">
      <li class="card step"><span class="num">1</span><h3>A change is requested</h3><p>An instructor picks the lecture, describes the change and gives a reason.</p></li>
      <li class="card step"><span class="num">2</span><h3>The change is reviewed</h3><p>The timetable coordinator approves or rejects it. Only approved changes reach students.</p></li>
      <li class="card step"><span class="num">3</span><h3>Students are notified</h3><p>Affected students see the original schedule, the update, the reason and the notice given.</p></li>
    </ol>
  </div>
</section>

<section class="section alt" id="policy">
  <div class="section-in policy">
    <div>
      <h2>The 24-hour notice policy</h2>
      <p class="lead">NoticeDiff records and measures notice. It does not enforce it.</p>
    </div>
    <div class="policy-text">
      <p>The standard rule is that a change request should be submitted at least 24 hours before the lecture starts. The system calculates that deadline from the original start time and records whether each request met it.</p>
      <p>Genuine emergencies happen. An instructor can submit a late request with a reason. It is clearly marked as late notice, and students still see it straight away once approved.</p>
      <p>NoticeDiff cannot make instructors use it, and what follows a late notice is up to your institution's policy.</p>
    </div>
  </div>
</section>

<section class="section" id="about">
  <div class="section-in policy">
    <div>
      <h2>About NoticeDiff</h2>
    </div>
    <div class="policy-text">
      <p>Students plan classes, commutes and personal commitments around a semester timetable. When a lecture is cancelled or moved at short notice, students who travel on fixed transport can be hit hardest.</p>
      <p>NoticeDiff is a university web project that makes schedule changes traceable: what changed, who requested it, who approved it, and when students were told.</p>
    </div>
  </div>
</section>

<section class="final-cta">
  <h2>Know before you go.</h2>
  <p>Create a student account to see your timetable changes.</p>
  <a class="btn btn-lg" href="<?= BASE_URL ?>/auth/register.php">Get started</a>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>