<?php
$title = 'Directions & Map';
$metaDescription = 'Find directions to Daytona Supply — map and driving instructions to 1022 Reed Canal Rd.';
include __DIR__ . '/includes/header.php';
?>
<main id="main" class="site-main" role="main">
  <section class="container" style="padding:28px 0;">
    <!-- Removed top Directions heading for a cleaner start with the map -->

    <div class="map-card">
      <div class="map-aspect">
        <iframe class="map-frame" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://maps.google.com/maps?width=600&height=400&hl=en&q=1022%20reed%20canal%20rd&t=&z=14&ie=UTF8&iwloc=B&output=embed" aria-label="Google map: 1022 Reed Canal Rd"></iframe>
      </div>
      <div class="map-meta">
        <div>
          <strong>Address</strong><br>
          1022 Reed Canal Rd<br>
          South Daytona, FL 32119
        </div>
        <div class="map-actions">
          <a class="btn-open-maps" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=1022+Reed+Canal+Rd+South+Daytona+FL+32119">Open in Google Maps</a>
          <a class="btn-call" href="tel:13867887009">Call: (386) 788-7009</a>
        </div>
      </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
