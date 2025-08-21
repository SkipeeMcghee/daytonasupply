<?php
// Manager Portal: allows office managers to manage orders, customers and products.
// All header() redirects occur before any HTML is sent to avoid "headers already sent" warnings.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Start the session if it hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set a default page title; header.php will use this value when included
$title = 'Manager Portal';

// Placeholder for login error messages
$loginError = '';

// If the admin is not logged in, handle login attempts
if (!isset($_SESSION['admin'])) {
    // If a login attempt is being made, validate the password
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        // Limit password length to a reasonable cap to avoid abuse while
        // preserving the user's intended value. Do not trim as whitespace
        // may be meaningful for passwords.
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) > 256) {
            $password = substr($password, 0, 256);
        }
        $db = getDb();
        $stmt = $db->query('SELECT password_hash FROM admin LIMIT 1');
        $hash = $stmt->fetchColumn();
        if ($hash && password_verify($password, $hash)) {
            // Successful login: set session and redirect to self
            $_SESSION['admin'] = true;
            header('Location: managerportal.php');
            exit;
        } else {
            $loginError = 'Incorrect password.';
        }
    }
    // Not logged in: show login form.  Include header now that no redirects remain.
    require_once __DIR__ . '/includes/header.php';
    ?>
    <h2>Office Manager Login</h2>
    <?php if ($loginError): ?>
        <p class="error"><?php echo htmlspecialchars($loginError); ?></p>
    <?php endif; ?>
    <form method="post" action="">
        <p>Password: <input type="password" name="password" required></p>
        <p><button type="submit">Login</button></p>
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    return;
}

// Admin is logged in: handle all state-changing actions BEFORE sending any HTML
// Handle bulk verify/unverify/delete for customers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk verify selected unverified customers
    if (isset($_POST['bulk_verify']) && !empty($_POST['unverified_ids'])) {
        foreach ($_POST['unverified_ids'] as $id) {
            setCustomerVerifiedStatus((int)$id, true);
        }
        header('Location: managerportal.php');
        exit;
    }
    // Bulk unverify selected verified customers
    if (isset($_POST['bulk_unverify']) && !empty($_POST['verified_ids'])) {
        foreach ($_POST['verified_ids'] as $id) {
            setCustomerVerifiedStatus((int)$id, false);
        }
        header('Location: managerportal.php');
        exit;
    }
    // Bulk delete selected verified customers
    if (isset($_POST['bulk_delete_verified']) && !empty($_POST['verified_ids'])) {
        $ids = array_map('intval', $_POST['verified_ids']);
        $db = getDb();
        $in  = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $db->prepare("DELETE FROM customers WHERE id IN ($in) AND is_verified = 1");
        $stmt->execute($ids);
        header('Location: managerportal.php');
        exit;
    }
}
// Handle mass delete of unverified customers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mass_delete_unverified'])) {
    $ids = array_map('intval', $_POST['unverified_ids'] ?? []);
    if ($ids) {
        $db = getDb();
        $in  = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $db->prepare("DELETE FROM customers WHERE id IN ($in) AND is_verified = 0");
        $stmt->execute($ids);
    }
    header('Location: managerportal.php');
    exit;
}
// Handle customer verification/unverification
if (isset($_GET['verify_customer'])) {
    $custId = (int)$_GET['verify_customer'];
    setCustomerVerifiedStatus($custId, true);
    header('Location: managerportal.php');
    exit;
}
if (isset($_GET['unverify_customer'])) {
    $custId = (int)$_GET['unverify_customer'];
    setCustomerVerifiedStatus($custId, false);
    header('Location: managerportal.php');
    exit;
}

// Approve or reject orders
if (isset($_GET['approve_order'])) {
    $orderId = (int)$_GET['approve_order'];
    updateOrderStatus($orderId, 'Approved');
    $filterRedirect = isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '';
    header('Location: managerportal.php' . $filterRedirect . '#order-' . $orderId);
    exit;
}
if (isset($_GET['reject_order'])) {
    $orderId = (int)$_GET['reject_order'];
    updateOrderStatus($orderId, 'Rejected');
    $filterRedirect = isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '';
    header('Location: managerportal.php' . $filterRedirect . '#order-' . $orderId);
    exit;
}

// Archive or unarchive orders
if (isset($_GET['archive_order'])) {
    $orderId = (int)$_GET['archive_order'];
    archiveOrder($orderId, true);
    // Preserve the view parameter when redirecting
    $filterRedirect = isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '';
    header('Location: managerportal.php' . $filterRedirect . '#order-' . $orderId);
    exit;
}
if (isset($_GET['unarchive_order'])) {
    $orderId = (int)$_GET['unarchive_order'];
    archiveOrder($orderId, false);
    $filterRedirect = isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '';
    header('Location: managerportal.php' . $filterRedirect . '#order-' . $orderId);
    exit;
}

// Delete a customer
if (isset($_GET['delete_customer'])) {
    $custId = (int)$_GET['delete_customer'];
    deleteCustomer($custId);
    // After deletion stay on same view
    $view = isset($_GET['view']) ? '&view=' . urlencode($_GET['view']) : '';
    header('Location: managerportal.php' . ($view ? '?' . ltrim($view, '&') : ''));
    exit;
}

// Delete a product
if (isset($_GET['delete_product'])) {
    $prodId = (int)$_GET['delete_product'];
    deleteProduct($prodId);
    header('Location: managerportal.php');
    exit;
}

// Handle POST actions for saving products, adding products, and saving customers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save products
    if (isset($_POST['save_products'])) {
        $products = getAllProducts();
        foreach ($products as $prod) {
            $id = (int)$prod['id'];
            $nameField = 'name_' . $id;
            $descField = 'desc_' . $id;
            $priceField = 'price_' . $id;
            if (isset($_POST[$nameField], $_POST[$priceField])) {
                $data = [
                    'name' => normalizeScalar($_POST[$nameField] ?? '', 128, ''),
                    'description' => normalizeScalar($_POST[$descField] ?? '', 512, ''),
                    'price' => (float)($_POST[$priceField] ?? 0.0)
                ];
                saveProduct($data, $id);
            }
        }
    header('Location: managerportal.php');
        exit;
    }
    // Add a new product
    if (isset($_POST['add_product'])) {
        $name = normalizeScalar($_POST['name'] ?? '', 128, '');
        $desc = normalizeScalar($_POST['description'] ?? '', 512, '');
        $price = (float)($_POST['price'] ?? 0.0);
        if ($name !== '') {
            saveProduct(['name' => $name, 'description' => $desc, 'price' => $price]);
        }
    header('Location: managerportal.php');
        exit;
    }
    // Save customer changes
    if (isset($_POST['save_customers'])) {
        $customers = getAllCustomers();
        foreach ($customers as $cust) {
            $id = (int)$cust['id'];
            $nameField = 'c_name_' . $id;
            $bizField = 'c_business_' . $id;
            $phoneField = 'c_phone_' . $id;
            $emailField = 'c_email_' . $id;
            $billField = 'c_bill_' . $id;
            $shipField = 'c_ship_' . $id;
            if (isset($_POST[$nameField], $_POST[$emailField])) {
                updateCustomer($id, [
                    'name' => normalizeScalar($_POST[$nameField] ?? '', 128, ''),
                    'business_name' => normalizeScalar($_POST[$bizField] ?? '', 128, ''),
                    'phone' => normalizeScalar($_POST[$phoneField] ?? '', 32, ''),
                    'email' => normalizeScalar($_POST[$emailField] ?? '', 254, ''),
                    'billing_address' => normalizeScalar($_POST[$billField] ?? '', 255, ''),
                    'shipping_address' => normalizeScalar($_POST[$shipField] ?? '', 255, '')
                ]);
            }
        }
        header('Location: managerportal.php');
        exit;
    }
}

// At this point all actions are complete. Determine which set of orders to display
// Orders filter: all | pending | approved | rejected | archived
$filter = isset($_GET['filter']) ? strtolower(trim($_GET['filter'])) : 'all';
// Fetch archived-only when requested, otherwise fetch active orders only
if ($filter === 'archived') {
    $orders = getAllOrders(false, true);
} else {
    // active orders only (archived excluded)
    $orders = getAllOrders(false, false);
    if ($filter === 'pending') {
        $orders = array_values(array_filter($orders, function($o) { return strcasecmp($o['status'] ?? '', 'Pending') === 0; }));
    } elseif ($filter === 'approved') {
        $orders = array_values(array_filter($orders, function($o) { return strcasecmp($o['status'] ?? '', 'Approved') === 0; }));
    } elseif ($filter === 'rejected') {
        $orders = array_values(array_filter($orders, function($o) { return strcasecmp($o['status'] ?? '', 'Rejected') === 0; }));
    }
}
// Customers: separate lists for unverified and verified
$unverifiedCustomers = getCustomersByVerified(false);
$verifiedCustomers = getCustomersByVerified(true);
$products = getAllProducts();

// Whether we're showing archived orders (used by the toggle link below)
$showArchived = ($filter === 'archived');

// Include the header now that no further redirects will occur
require_once __DIR__ . '/includes/header.php';
?>

<h2>Manager Portal</h2>

<!-- Toolbar removed; Update Inventory button moved to Products section -->

<section id="orders-section">
    <h3>Orders</h3>
    <?php
        // Friendly heading describing which list is shown
        $filterLabels = [
            'all' => 'All Orders',
            'pending' => 'Pending Orders',
            'approved' => 'Approved Orders',
            'rejected' => 'Rejected Orders',
            'archived' => 'Archived Orders'
        ];
    ?>
    <div style="margin-bottom:6px;font-weight:700;">Showing: <?php echo htmlspecialchars($filterLabels[$filter] ?? 'Orders'); ?></div>

    <div style="margin-bottom:8px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php
            // Define label and colors for each filter button
            $filters = [
                'all' => ['label' => 'Show All', 'bg' => '#f0f0f0', 'color' => '#000'],
                'pending' => ['label' => 'Show Pending', 'bg' => '#ffc107', 'color' => '#000'],
                'approved' => ['label' => 'Show Approved', 'bg' => '#198754', 'color' => '#fff'],
                'rejected' => ['label' => 'Show Rejected', 'bg' => '#dc3545', 'color' => '#fff'],
                'archived' => ['label' => 'Show Archived', 'bg' => '#0d6efd', 'color' => '#fff']
            ];
            foreach ($filters as $key => $meta) {
                $isActive = ($filter === $key);
                $bg = $meta['bg'];
                $color = $meta['color'];
                // Non-active buttons get a drop shadow to appear raised; active button looks "pressed" (no shadow + slight translate)
                $shadow = $isActive ? '' : 'box-shadow:0 6px 18px rgba(0,0,0,0.12);';
                $pressed = $isActive ? 'transform:translateY(1px);' : '';
                $url = 'managerportal.php?filter=' . urlencode($key);
                echo '<a href="' . htmlspecialchars($url) . '" style="padding:8px 12px;border-radius:6px;text-decoration:none;font-weight:600; background:' . $bg . '; color:' . $color . '; ' . $shadow . ' ' . $pressed . '">' . htmlspecialchars($meta['label']) . '</a>';
            }
        ?>
    </div>
    <?php foreach ($orders as $order): ?>
    <div id="order-<?php echo $order['id']; ?>" class="order-group">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <div><strong>Order #<?php echo $order['id']; ?></strong> — <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?> by <?php echo htmlspecialchars((getCustomerById((int)$order['customer_id'])['name'] ?? 'Unknown')); ?></div>
        <div><span class="order-toggle" data-order="<?php echo $order['id']; ?>">Collapse</span></div>
    </div>
    <table class="admin-table order-items" data-order="<?php echo $order['id']; ?>">
        <tr>
            <th>ID</th>
            <th>Date</th>
            <th>Customer</th>
            <th>SKU</th>
            <th>Description</th>
            <th>Quantity</th>
            <th class="numeric">Rate</th>
            <th class="numeric">Price</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php
            $cust = getCustomerById((int)$order['customer_id']);
            $items = getOrderItems((int)$order['id']);
            $orderTotal = 0.0;
        ?>
        <?php if (!empty($items)): ?>
            <?php $firstItem = true; foreach ($items as $it): ?>
                <?php
                    $prod = getProductById((int)$it['product_id']);
                    $sku = $prod ? htmlspecialchars($prod['name']) : $it['product_id'];
                    $desc = $prod ? htmlspecialchars($prod['description'] ?? $prod['name']) : 'Unknown product';
                    $qty = (int)$it['quantity'];
                    $rate = $prod ? (float)$prod['price'] : 0.0;
                    $price = $rate * $qty;
                    $orderTotal += $price;
                ?>
                <tr>
                    <?php if ($firstItem): ?>
                        <td><?php echo $order['id']; ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?></td>
                        <td><?php echo htmlspecialchars($cust['name'] ?? 'Unknown'); ?></td>
                    <?php else: ?>
                        <td></td>
                        <td></td>
                        <td></td>
                    <?php endif; ?>
                    <td><?php echo $sku; ?></td>
                    <td><?php echo $desc; ?></td>
                    <td class="numeric"><?php echo $qty; ?></td>
                    <td class="numeric">$<?php echo number_format($rate, 2); ?></td>
                    <td class="numeric">$<?php echo number_format($price, 2); ?></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php $firstItem = false; ?>
            <?php endforeach; ?>
            <tr class="order-total-row">
                <td colspan="7" style="text-align:right"><strong>Total:</strong></td>
                <td class="numeric"><strong>$<?php echo number_format($orderTotal, 2); ?></strong></td>
                        <td><?php echo htmlspecialchars($order['status']); ?></td>
                <td>
                    <?php
                        // Preserve the current filter in action links so returning view is consistent
                        $filterParam = $filter ? '&filter=' . urlencode($filter) : '';
                    ?>
                    <a class="action-btn action-approve small" href="?approve_order=<?php echo $order['id']; ?><?php echo $filterParam; ?>">Approve</a>
                    <a class="action-btn action-reject small" href="?reject_order=<?php echo $order['id']; ?><?php echo $filterParam; ?>">Reject</a>
                    <?php if (!empty($order['archived']) && $order['archived'] == 1): ?>
                        <a class="action-btn action-unarchive small" href="?unarchive_order=<?php echo $order['id']; ?><?php echo $filterParam; ?>">Unarchive</a>
                    <?php else: ?>
                        <a class="action-btn action-archive small" href="?archive_order=<?php echo $order['id']; ?><?php echo $filterParam; ?>">Archive</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php else: ?>
            <tr><td colspan="10">No items for order #<?php echo $order['id']; ?></td></tr>
        <?php endif; ?>
    </table>
    </div>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?>
        <p>No orders found.</p>
    <?php endif; ?>
</section>

<section>
    <h3>Customers</h3>
    <!-- Unverified/Pending Customers (top) -->
    <h4>Unverified / Pending Customers</h4>
    <form method="post" action="" id="bulkUnverifiedForm">
        <div style="margin-bottom:10px;display:flex;gap:10px;">
            <button type="submit" name="bulk_verify" onclick="return confirm('Verify all selected customers?');" style="background:#198754;color:#fff;padding:8px 16px;border:none;border-radius:4px;font-weight:600;">Verify Selected</button>
            <button type="submit" name="mass_delete_unverified" onclick="return confirmMassDelete();" style="background:#dc3545;color:#fff;padding:8px 16px;border:none;border-radius:4px;font-weight:600;">Delete Selected</button>
        </div>
        <table class="admin-table">
            <tr><th><input type="checkbox" id="selectAllUnverified"></th><th>ID</th><th>Name</th><th>Business</th><th>Phone</th><th>Email</th><th>Billing</th><th>Shipping</th><th>Actions</th></tr>
            <?php foreach ($unverifiedCustomers as $cust): ?>
                <tr>
                    <td><input type="checkbox" name="unverified_ids[]" value="<?= $cust['id'] ?>"></td>
                    <td><?= $cust['id'] ?></td>
                    <td><?= htmlspecialchars($cust['name']) ?></td>
                    <td><?= htmlspecialchars($cust['business_name']) ?></td>
                    <td><?= htmlspecialchars($cust['phone']) ?></td>
                    <td><?= htmlspecialchars($cust['email']) ?></td>
                    <td><?= htmlspecialchars($cust['billing_address']) ?></td>
                    <td><?= htmlspecialchars($cust['shipping_address']) ?></td>
                    <td>
                        <a class="mgr-btn mgr-verify" href="?verify_customer=<?= $cust['id'] ?>">Verify</a>
                        <a class="mgr-btn mgr-delete" href="?delete_customer=<?= $cust['id'] ?>" onclick="return confirm('Are you sure you want to delete this customer? This will remove all of their orders.');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($unverifiedCustomers)): ?>
                <tr><td colspan="9">No unverified customers found.</td></tr>
            <?php endif; ?>
        </table>
    </form>
    <script>
    // Select all checkboxes
    document.getElementById('selectAllUnverified').onclick = function() {
        var boxes = document.querySelectorAll('input[name="unverified_ids[]"]');
        for (var i = 0; i < boxes.length; i++) boxes[i].checked = this.checked;
    };
    function confirmMassDelete() {
        return confirm('Are you sure you want to delete all selected unverified customers? This cannot be undone.');
    }
    </script>
    <!-- Verified Customers (below) -->
    <h4>Verified Customers</h4>
    <form method="post" action="" id="bulkVerifiedForm">
        <div style="margin-bottom:10px;display:flex;gap:10px;">
            <button type="submit" name="bulk_unverify" onclick="return confirm('Unverify all selected customers?');" style="background:#ffc107;color:#000;padding:8px 16px;border:none;border-radius:4px;font-weight:600;">Unverify Selected</button>
            <button type="submit" name="bulk_delete_verified" onclick="return confirm('Delete all selected verified customers? This cannot be undone.');" style="background:#dc3545;color:#fff;padding:8px 16px;border:none;border-radius:4px;font-weight:600;">Delete Selected</button>
        </div>
        <table class="admin-table">
            <tr><th><input type="checkbox" id="selectAllVerified"></th><th>ID</th><th>Name</th><th>Business</th><th>Phone</th><th>Email</th><th>Billing</th><th>Shipping</th><th>Actions</th></tr>
            <?php foreach ($verifiedCustomers as $cust): ?>
                <tr>
                    <td><input type="checkbox" name="verified_ids[]" value="<?= $cust['id'] ?>"></td>
                    <td><?= $cust['id'] ?></td>
                    <td><?= htmlspecialchars($cust['name']) ?></td>
                    <td><?= htmlspecialchars($cust['business_name']) ?></td>
                    <td><?= htmlspecialchars($cust['phone']) ?></td>
                    <td><?= htmlspecialchars($cust['email']) ?></td>
                    <td><?= htmlspecialchars($cust['billing_address']) ?></td>
                    <td><?= htmlspecialchars($cust['shipping_address']) ?></td>
                    <td>
                        <a class="mgr-btn mgr-unverify" href="?unverify_customer=<?= $cust['id'] ?>">Unverify</a>
                        <a class="mgr-btn mgr-delete" href="?delete_customer=<?= $cust['id'] ?>" onclick="return confirm('Are you sure you want to delete this customer? This will remove all of their orders.');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($verifiedCustomers)): ?>
                <tr><td colspan="9">No verified customers found.</td></tr>
            <?php endif; ?>
        </table>
    </form>
</section>

<section>
    <h3>Products</h3>
    <div style="display:flex; justify-content:flex-start; margin:8px 0 12px 0;">
        <a href="admin/update_inventory.php" style="background:#0b5ed7; color:#fff; padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600;">Update Inventory</a>
    </div>
    <form method="post" action="">
        <input type="hidden" name="save_products" value="1">
        <table class="admin-table">
            <tr><th>ID</th><th>Name</th><th>Description</th><th>Price</th><th>Actions</th></tr>
            <?php $rowNum = 1; foreach ($products as $prod): ?>
                <tr>
                    <!-- Display a sequential row number instead of the raw product ID -->
                    <td><?php echo $rowNum; ?></td>
                    <td><input type="text" name="name_<?php echo $prod['id']; ?>" value="<?php echo htmlspecialchars($prod['name']); ?>"></td>
                    <td><input type="text" name="desc_<?php echo $prod['id']; ?>" value="<?php echo htmlspecialchars($prod['description']); ?>"></td>
                    <td><input type="number" step="0.01" name="price_<?php echo $prod['id']; ?>" value="<?php echo number_format($prod['price'], 2); ?>"></td>
                    <td><a class="mgr-btn mgr-product-delete" href="?delete_product=<?php echo $prod['id']; ?>" onclick="return confirm('Delete this product?');">Delete</a></td>
                </tr>
                <?php $rowNum++; ?>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
                <tr><td colspan="5">No products found.</td></tr>
            <?php endif; ?>
        </table>
        <p><button type="submit">Save Product Changes</button></p>
    </form>
    <h4>Add Product</h4>
    <form method="post" action="">
        <input type="hidden" name="add_product" value="1">
        <p>Name: <input type="text" name="name" required></p>
        <p>Description: <input type="text" name="description"></p>
        <p>Price: <input type="number" step="0.01" name="price" required></p>
        <p><button type="submit">Add Product</button></p>
    </form>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>