<?php
// Shared footer for all pages.  Closes the <main> element and adds
// a simple footer section.
?>
    </main>
    <footer class="site-footer" role="contentinfo">
        <div class="container footer-grid">
            <div class="f-brand">
                <img src="assets/images/DaytonaSupplyDSlogo.png" alt="Daytona Supply" class="f-logo">
                <address>
                    Daytona Supply<br>
                    123 Supply Ave<br>
                    Daytona Beach, FL 32114
                </address>
            </div>
            <div class="f-links">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="catalogue.php">Catalog</a></li>
                    <li><a href="privacy.php">Privacy</a></li>
                    <li><a href="terms.php">Terms</a></li>
                </ul>
            </div>
            <div class="f-news">
                <h4>Newsletter</h4>
                <p>Get product updates and local deals.</p>
                <form id="newsletter" action="" method="post">
                    <label for="news-email" class="sr-only">Email address</label>
                    <input id="news-email" name="email" type="email" placeholder="you@company.com" required>
                    <button type="submit">Subscribe</button>
                </form>
            </div>
            <div class="f-social">
                <h4>Follow</h4>
                <ul>
                    <li><a href="#" aria-label="LinkedIn">LinkedIn</a></li>
                </ul>
            </div>
        </div>
        <div class="site-copyright">
            <small>&copy; <?php echo date('Y'); ?> Daytona Supply. All rights reserved.</small>
        </div>
    </footer>
    <script src="assets/scripts.js?v=<?php echo file_exists(__DIR__ . '/../assets/scripts.js') ? filemtime(__DIR__ . '/../assets/scripts.js') : time(); ?>" defer></script>
</body>
</html>