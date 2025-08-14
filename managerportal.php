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
        $password = $_POST['password'];
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

// Approve or reject orders
if (isset($_GET['approve_order'])) {
    $orderId = (int)$_GET['approve_order'];
    updateOrderStatus($orderId, 'Approved');
    header('Location: managerportal.php');
    exit;
}
if (isset($_GET['reject_order'])) {
    $orderId = (int)$_GET['reject_order'];
    updateOrderStatus($orderId, 'Rejected');
    header('Location: managerportal.php');
    exit;
}

// Archive or unarchive orders
if (isset($_GET['archive_order'])) {
    $orderId = (int)$_GET['archive_order'];
    archiveOrder($orderId, true);
    // Preserve the view parameter when redirecting
    $view = isset($_GET['view']) ? '&view=' . urlencode($_GET['view']) : '';
    header('Location: managerportal.php' . ($view ? '?' . ltrim($view, '&') : ''));
    exit;
}
if (isset($_GET['unarchive_order'])) {
    $orderId = (int)$_GET['unarchive_order'];
    archiveOrder($orderId, false);
    $view = isset($_GET['view']) ? '&view=' . urlencode($_GET['view']) : '';
    header('Location: managerportal.php' . ($view ? '?' . ltrim($view, '&') : ''));
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
                    'name' => trim($_POST[$nameField]),
                    'description' => trim($_POST[$descField] ?? ''),
                    'price' => (float)$_POST[$priceField]
                ];
                saveProduct($data, $id);
            }
        }
    header('Location: managerportal.php');
        exit;
    }
    // Add a new product
    if (isset($_POST['add_product'])) {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
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
                    'name' => trim($_POST[$nameField]),
                    'business_name' => trim($_POST[$bizField] ?? ''),
                    'phone' => trim($_POST[$phoneField] ?? ''),
                    'email' => trim($_POST[$emailField]),
                    'billing_address' => trim($_POST[$billField] ?? ''),
                    'shipping_address' => trim($_POST[$shipField] ?? '')
                ]);
            }
        }
        header('Location: managerportal.php');
        exit;
    }
}

// At this point all actions are complete.  Determine which set of orders to display
// and which customers to show (all vs. pending verification).
$showArchived = (isset($_GET['view']) && $_GET['view'] === 'archived');
if ($showArchived) {
    // Show only archived orders
    $orders = getAllOrders(true, true);
} else {
    // Show only active (non-archived) orders
    $orders = getAllOrders(false, false);
}
// Determine whether to show only customers pending verification
$showPendingCustomers = (isset($_GET['cust_view']) && $_GET['cust_view'] === 'pending');
if ($showPendingCustomers) {
    $customers = getAllCustomers(true);
} else {
    $customers = getAllCustomers(false);
}
$products = getAllProducts();

// Include the header now that no further redirects will occur
require_once __DIR__ . '/includes/header.php';
?>

<h2>Manager Portal</h2>

<!-- Toolbar with inventory management -->
<div style="display:flex; justify-content:flex-end; margin:10px 0 20px 0;">
    <!-- Use a relative URL so the link works when the site is served from a subfolder -->
    <a href="admin/update_inventory.php" style="background:#0b5ed7; color:#fff; padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600;">Update Inventory</a>
</div>

<section>
    <h3>Orders</h3>
    <!-- Toggle between active and archived orders -->
    <p>
        <?php if ($showArchived): ?>
            <a href="?view=active">Show Active Orders</a>
        <?php else: ?>
            <a href="?view=archived">Show Archived Orders</a>
        <?php endif; ?>
    </p>
    <table class="admin-table">
        <tr><th>ID</th><th>Date</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Actions</th></tr>
        <?php foreach ($orders as $order): ?>
            <?php
                $cust = getCustomerById((int)$order['customer_id']);
                $items = getOrderItems((int)$order['id']);
                $descArr = [];
                foreach ($items as $it) {
                    $prod = getProductById((int)$it['product_id']);
                    if ($prod) {
                        $descArr[] = htmlspecialchars($prod['name']) . ' x' . (int)$it['quantity'];
                    }
                }
            ?>
            <tr>
                <td><?php echo $order['id']; ?></td>
                <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?></td>
                <td><?php echo htmlspecialchars($cust['name'] ?? 'Unknown'); ?></td>
                <td><?php echo implode(', ', $descArr); ?></td>
                <td>$<?php echo number_format($order['total'], 2); ?></td>
                <td><?php echo htmlspecialchars($order['status']); ?></td>
                <td>
                    <a href="?approve_order=<?php echo $order['id']; ?><?php echo $showArchived ? '&view=archived' : ''; ?>">Approve</a>
                    |
                    <a href="?reject_order=<?php echo $order['id']; ?><?php echo $showArchived ? '&view=archived' : ''; ?>">Reject</a>
                    |
                    <?php if (!empty($order['archived']) && $order['archived'] == 1): ?>
                        <a href="?unarchive_order=<?php echo $order['id']; ?><?php echo $showArchived ? '&view=archived' : ''; ?>">Unarchive</a>
                    <?php else: ?>
                        <a href="?archive_order=<?php echo $order['id']; ?><?php echo $showArchived ? '&view=archived' : ''; ?>">Archive</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?>
            <tr><td colspan="7">No orders found.</td></tr>
        <?php endif; ?>
    </table>
</section>

<section>
    <h3>Customers</h3>
    <!-- Toggle between all customers and pending (unverified) customers -->
    <p>
        <?php
        // Build a query string that preserves the current order view (archived vs active)
        $orderViewParam = $showArchived ? 'view=archived' : '';
        if ($showPendingCustomers) {
            // Currently showing pending customers; link back to all
            $qs = $orderViewParam ? '?' . $orderViewParam : '';
            echo '<a href="' . htmlspecialchars('managerportal.php' . $qs) . '">Show All Customers</a>';
        } else {
            // Show pending customers link
            $qs = '';
            if ($orderViewParam !== '') {
                $qs = '?'.$orderViewParam.'&cust_view=pending';
            } else {
                $qs = '?cust_view=pending';
            }
            echo '<a href="' . htmlspecialchars('managerportal.php' . $qs) . '">Show Pending Customers</a>';
        }
        ?>
    </p>
    <form method="post" action="">
        <input type="hidden" name="save_customers" value="1">
        <table class="admin-table">
            <tr><th>ID</th><th>Name</th><th>Business</th><th>Phone</th><th>Email</th><th>Billing</th><th>Shipping</th><th>Verified</th><th>Actions</th></tr>
            <?php foreach ($customers as $cust): ?>
                <tr>
                    <td><?php echo $cust['id']; ?></td>
                    <td><input type="text" name="c_name_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['name']); ?>"></td>
                    <td><input type="text" name="c_business_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['business_name']); ?>"></td>
                    <td><input type="text" name="c_phone_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['phone']); ?>"></td>
                    <td><input type="email" name="c_email_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['email']); ?>"></td>
                    <td><input type="text" name="c_bill_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['billing_address']); ?>"></td>
                    <td><input type="text" name="c_ship_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['shipping_address']); ?>"></td>
                    <td><?php echo (isset($cust['is_verified']) && (int)$cust['is_verified'] === 1) ? 'Yes' : 'No'; ?></td>
                    <td><a href="?delete_customer=<?php echo $cust['id']; ?><?php echo $showArchived ? '&view=archived' : ''; ?><?php echo $showPendingCustomers ? '&cust_view=pending' : ''; ?>" onclick="return confirm('Are you sure you want to delete this customer? This will remove all of their orders.');">Delete</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
                <tr><td colspan="9">No customers found.</td></tr>
            <?php endif; ?>
        </table>
        <p><button type="submit">Save Customer Changes</button></p>
    </form>
</section>

<section>
    <h3>Products</h3>
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
                    <td><a href="?delete_product=<?php echo $prod['id']; ?>">Delete</a></td>
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