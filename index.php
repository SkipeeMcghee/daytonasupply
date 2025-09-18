<?php
// Modern homepage for Daytona Supply
// Mobile-first responsive layout with accessible components.
// Set page meta before including header
$title = 'Daytona Supply — Packaging & Janitorial Supplies';
$metaDescription = 'Daytona Supply is your local B2B partner for packaging, janitorial, and cleaning supplies. Fast local delivery, competitive pricing, and easy account management.';
require_once __DIR__ . '/includes/header.php';
?>
<main id="main" class="site-main" role="main">
	<!-- Hero Carousel -->
	<section id="content" class="hero-section" aria-label="Featured promotions">
		<div class="hero-wrap">
			<div class="hero-carousel" data-autoplay="true" data-interval="6000" aria-roledescription="carousel">
				<button class="carousel-control prev" aria-label="Previous slide">‹</button>
				<div class="carousel-track">
					<article class="carousel-slide" aria-hidden="false">
						<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Daytona Supply" class="slide-img" loading="lazy">
						<div class="slide-copy">
							<h1 class="slide-title">Local, Trusted Packaging & Janitorial Supply</h1>
							<p class="slide-sub">Thousands of products, next-day local delivery, and wholesale pricing for businesses.</p>
							<p><a class="cta" href="catalogue.php">Shop the Catalog</a></p>
						</div>
					</article>
					<article class="carousel-slide" aria-hidden="true">
						<img src="assets/images/boxey with smartphone.png" alt="Ordering on mobile" class="slide-img" loading="lazy">
						<div class="slide-copy">
							<h2 class="slide-title">Order Faster — Account Tools for Businesses</h2>
							<p class="slide-sub">Manage reorders, downloadable invoices, and bulk pricing.</p>
							<p><a class="cta" href="login.php">Create an Account</a></p>
						</div>
					</article>
					<article class="carousel-slide" aria-hidden="true">
						<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Partner support" class="slide-img" loading="lazy">
						<div class="slide-copy">
							<h2 class="slide-title">Dedicated Support for Vendors & Partners</h2>
							<p class="slide-sub">Marketing materials, custom packaging, and account reps to help you scale.</p>
							<p><a class="cta" href="managerportal.php">Partner Services</a></p>
						</div>
					</article>
				</div>
				<button class="carousel-control next" aria-label="Next slide">›</button>
				<div class="carousel-dots" role="tablist" aria-label="Slide dots"></div>
			</div>
		</div>
	</section>

	<!-- Promo tiles -->
	<section class="promo-tiles" aria-label="Quick actions">
		<div class="container grid promo-grid">
			<a class="promo card" href="catalogue.php">
				<h3>View Catalog</h3>
				<p>Explore our full product range and bulk pricing.</p>
			</a>
			<a class="promo card" href="login.php">
				<h3>Place an Order</h3>
				<p>Log in to access account pricing and reorders.</p>
			</a>
			<a class="promo card" href="login.php">
				<h3>Partner Packages</h3>
				<p>Custom programs for resellers and contractors.</p>
			</a>
		</div>
	</section>

	<!-- Messaging slider -->
	<section class="message-strip" aria-label="Why choose us">
		<div class="container">
			<ul class="message-list" aria-live="polite">
				<li>20,000+ items in stock</li>
				<li>Next-day local shipping</li>
				<li>Account reps & marketing support</li>
			</ul>
		</div>
	</section>

	<!-- Wide banner -->
	<section class="wide-banner" aria-hidden="false">
		<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Daytona Supply banner" loading="lazy">
		<div class="banner-overlay">
			<h2>Supply locally. Ship fast. Stay compliant.</h2>
		</div>
	</section>

	<!-- Category grid -->
	<section class="categories container" aria-label="Shop by category">
		<h2 class="sr-only">Shop by category</h2>
		<div class="grid categories-grid">
			<a class="category-card" href="catalogue.php?cat=packaging">
				<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Packaging" loading="lazy">
				<div class="cat-body">
					<h3>Packaging</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="catalogue.php?cat=janitorial">
				<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Janitorial" loading="lazy">
				<div class="cat-body">
					<h3>Janitorial</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="catalogue.php?cat=safety">
				<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Safety" loading="lazy">
				<div class="cat-body">
					<h3>Safety</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="catalogue.php?cat=paper">
				<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Paper & Wipes" loading="lazy">
				<div class="cat-body">
					<h3>Paper & Wipes</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="catalogue.php?cat=chemicals">
				<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Chemicals" loading="lazy">
				<div class="cat-body">
					<h3>Chemicals</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="catalogue.php?cat=equipment">
				<img src="assets/images/DaytonaSupplyDSlogo.png" alt="Equipment" loading="lazy">
				<div class="cat-body">
					<h3>Equipment</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
		</div>
	</section>

	<!-- Back to top -->
	<button id="backToTop" class="back-to-top" aria-label="Back to top">↑</button>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>