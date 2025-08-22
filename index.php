<?php
// Home page for Daytona Supply

require_once __DIR__ . '/includes/header.php';

$title = 'Home';
?>
<h2>Welcome to Daytona Supply</h2>
<!-- Feature hero: image + SEO-friendly copy + CTAs -->
<section class="home-hero" aria-label="Daytona Supply overview">
	<div class="hero-inner">
		<div class="hero-media">
			<img src="assets/images/boxey with smartphone.png" alt="Boxey holding a smartphone" class="hero-img">
		</div>
		<div class="hero-content">
			<h3>Your trusted supplier for janitorial, cleaning & packing supplies</h3>
			<p class="lead">Daytona Supply delivers high-quality janitorial supplies, commercial cleaning products, and packaging materials at competitive prices. Shop our full <a href="catalogue.php">catalogue of products</a>, place bulk orders online, and enjoy fast local delivery and dependable service.</p>

			<ul class="hero-features">
				<li>Competitive pricing on bulk orders</li>
				<li>Fast local delivery and reliable fulfillment</li>
				<li>Easy online ordering and account management</li>
			</ul>

			<div class="hero-ctas">
				<a class="view-btn" href="catalogue.php">Shop Catalogue</a>
				<a class="view-btn" href="signup.php">Create an Account</a>
			</div>
		</div>
	</div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>