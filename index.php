<?php
// Modern homepage for Daytona Supply
// Mobile-first responsive layout with accessible components.
// Set page meta before including header
$title = 'Daytona Supply — Packaging & Janitorial Supplies';
$metaDescription = 'Daytona Supply is your local B2B partner for packaging, janitorial, and cleaning supplies. Fast local delivery, competitive pricing, and easy account management.';
require_once __DIR__ . '/includes/header.php'; 
?>
	<!-- Hero Carousel -->
	<section id="content" class="hero-section" aria-label="Featured promotions">
		<div class="hero-wrap">
			<div class="hero-carousel" data-autoplay="true" data-interval="6000" aria-roledescription="carousel">
				<button class="carousel-control prev" aria-label="Previous slide">‹</button>
				<div class="carousel-track">
					<article class="carousel-slide" aria-hidden="false">
						<img src="assets/images/boxey with smartphone.png" alt="Ordering on mobile" class="slide-img" loading="lazy">
						<div class="slide-copy">
							<h1 class="slide-title">Local, Trusted Packaging & Janitorial Supply</h1>
							<p class="slide-sub">Hundreds of products, next-day local delivery, and wholesale pricing for businesses since 1987.</p>
							<p><a class="cta" href="catalogue.php">Shop the Catalog</a></p>
						</div>
					</article>
					<article class="carousel-slide" aria-hidden="true">
						<img src="assets/images/Tapey.png" alt="Tape products" class="slide-img" loading="lazy">
						<div class="slide-copy">
							<h2 class="slide-title">Order Faster — Account Tools for Businesses</h2>
							<p class="slide-sub">View past orders, browse products and place orders in minutes.</p>
							<?php $createAcctHref = isset($_SESSION['customer']) ? 'account.php' : 'signup.php'; ?>
							<p><a class="cta" href="<?php echo $createAcctHref; ?>">Create an Account</a></p>
						</div>
					</article>
					<article class="carousel-slide" aria-hidden="true">
						<img src="assets/images/juggo.png" alt="Packaging equipment" class="slide-img" loading="lazy">
						<div class="slide-copy">
							<h2 class="slide-title">Dedicated Support for Vendors & Partners</h2>
							<p class="slide-sub">Marketing materials, custom packaging, and account reps to help you scale.</p>
							<p><a class="cta" href="contact.php">Tell Us What You Need</a></p>
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
			<a class="promo card" href="account.php#your-orders">
				<h3>Check your Orders</h3>
				<p>Log in to access account and order details.</p>
			</a>
			<a class="promo card" href="shipping.php">
				<h3>Ship a Package</h3>
				<p>From an Authorized Fedex Ship Center.</p>
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
		<img src="assets/images/joe.jpg" alt="Daytona Supply banner" loading="lazy">
		<div class="banner-overlay">
			<h2>Supply locally. Ship fast. Stay compliant.</h2>
		</div>
	<div class="banner-bottom-left" aria-hidden="true">We accept packages of all sizes for delivery</div>
	<div class="banner-center-right" aria-hidden="true">Low freight costs, higher margins</div>
	</section>

	<!-- Category grid -->
	<section class="categories container" aria-label="Shop by category">
		<h2 class="sr-only">Shop by category</h2>
		<div class="grid categories-grid">
			<a class="category-card" href="products.php?cat=corrugated">
				<img src="assets/images/boxes.png" alt="Corrugated Boxes" loading="lazy">
				<div class="cat-body">
					<h3>Corrugated Boxes</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="products.php?cat=tape">
				<img src="assets/images/tape.png" alt="Tape" loading="lazy">
				<div class="cat-body">
					<h3>Tape</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="products.php?cat=packaging-supplies">
				<img src="assets/images/stretchfilm.png" alt="Packaging Supplies" loading="lazy">
				<div class="cat-body">
					<h3>Packaging Supplies</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="products.php?cat=paper-products">
				<img src="assets/images/paper.png" alt="Paper Products" loading="lazy">
				<div class="cat-body">
					<h3>Paper Products</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="products.php?cat=bubble-products">
				<img src="assets/images/bubble.png" alt="Bubble Products" loading="lazy">
				<div class="cat-body">
					<h3>Bubble Products</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
			<a class="category-card" href="products.php?cat=foam">
				<img src="assets/images/foam.png" alt="Foam" loading="lazy">
				<div class="cat-body">
					<h3>Foam</h3>
					<button class="shop-btn">Shop Category</button>
				</div>
			</a>
		</div>
	</section>

	<!-- Back to top -->
	<div id="backToTopWrap" class="back-to-top-wrap" aria-hidden="true">
		<span class="back-to-top-label">Return To Top</span>
		<button id="backToTop" class="back-to-top" aria-label="Back to top">↑</button>
	</div>
<?php include __DIR__ . '/includes/footer.php'; ?>