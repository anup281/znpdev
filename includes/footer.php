<?php require_once __DIR__.'/workspace_bootstrap.php'; ?>
<?php if(setting('newsletter_enabled','1')==='1'):?>
<section class="newsletter-signup" id="newsletter-signup">
  <div class="container newsletter-signup-inner">
    <div class="newsletter-signup-copy">
      <p><?=e(setting('newsletter_footer_text','Receive updates on new developments, investment opportunities, and company news.'))?></p>
    </div>
    <form action="/newsletter_subscribe.php" method="post" class="newsletter-signup-form" id="newsletter-signup-form" novalidate>
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
      <input class="newsletter-honeypot" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
      <input type="text" name="full_name" placeholder="Name" aria-label="Name" autocomplete="name">
      <input type="email" name="email" placeholder="Email address" aria-label="Email address" autocomplete="email" required>
      <button type="submit">Subscribe</button>
    </form>
    <div id="newsletter-signup-status" class="newsletter-signup-status" aria-live="polite"></div>
  </div>
</section>
<?php endif;?>
<footer class="site-footer">
  <div class="container footer">
    <div class="public-legal-links" aria-label="Legal information">
      <a href="/privacy">Privacy Policy</a>
      <a href="/terms">Terms of Use</a>
      <a href="/cookies">Cookie Policy</a>
      <a href="/accessibility">Accessibility</a>
    </div>
    <div class="public-footer-meta">
      <span>© <?=date('Y')?> ZNP Development</span>
      <span><?=e(function_exists('znp_application_version_label') ? znp_application_version_label() : 'ZNP Development Platform | Version 5.2.7')?></span>
    </div>
  </div>
</footer>
<div id="public-toast-tray" class="public-toast-tray" aria-live="polite" aria-atomic="false"></div>
<script src="/assets/site.js"></script>
<script src="/assets/newsletter-signup.js" defer></script>
</body>
</html>
