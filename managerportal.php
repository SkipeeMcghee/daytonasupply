<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Manager Portal';

// Handle admin session
if (!isset($_SESSION['admin']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Not logged in; show login form
    ?>
    <h2>Office Manager Login</h2>
    <form method="post" action="">
        <p>Password: <input type="password" name="password" required></p>
        <p><button type="submit">Login</button></p>
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    return;
}

// Authenticate admin
if (!isset($_SESSION['admin']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = $_POST['password'];
    $db = getDb();
    $stmt = $db->query('SELECT password_hash FROM admin LIMIT 1');
    $hash = $stmt->fetchColumn();
    if ($hash && password_verify($password, $hash)) {
        $_SESSION['admin'] = true;
        header('Location: /managerportal.php');
        exit;
    } else {
        echo '<p class="error">Incorrect password.</p>';
        include __DIR__ . '/includes/footer.php';
        return;
    }
}

// Only reach here if admin logged in

// Handle actions (approve/reject, delete product)
if (isset($_GET['approve_order'])) {
    $orderId = (int)$_GET['approve_order'];
    updateOrderStatus($orderId, 'Approved');
    header('Location: /managerportal.php');
    exit;
}
if (isset($_GET['reject_order'])) {
    $orderId = (int)$_GET['reject_order'];
    updateOrderStatus($orderId, 'Rejected');
    header('Location: /managerportal.php');
    exit;
}
if (isset($_GET['delete_product'])) {
    $prodId = (int)$_GET['delete_product'];
    deleteProduct($prodId);
    header('Location: /managerportal.php');
    exit;
}

// Handle POST actions for products and customers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update products
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
        header('Location: /managerportal.php');
        exit;
    }
    // Add product
    if (isset($_POST['add_product'])) {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        if ($name !== '') {
            saveProduct(['name' => $name, 'description' => $desc, 'price' => $price]);
        }
        header('Location: /managerportal.php');
        exit;
    }
    // Update customers
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
        header('Location: /managerportal.php');
        exit;
    }
}

// Fetch data for display
$orders = getAllOrders();
$customers = getAllCustomers();
$products = getAllProducts();
?>

<h2>Manager Portal</h2>

<!-- Toolbar with inventory management -->
<div style="display:flex; justify-content:flex-end; margin:10px 0 20px 0;">
    <a href="/admin/update_inventory.php" style="background:#0b5ed7; color:#fff; padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600;">Update Inventory</a>
</div>

<div style="margin-bottom: 20px;">
    <!-- Intentionally left blank: update inventory not implemented in this demo -->
</div>

<section>
    <h3>Orders</h3>
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
                    <?php if ($order['status'] === 'Pending'): ?>
                        <a href="?approve_order=<?php echo $order['id']; ?>">Approve</a> |
                        <a href="?reject_order=<?php echo $order['id']; ?>">Reject</a>
                    <?php else: ?>
                        &ndash;
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
    <form method="post" action="">
        <input type="hidden" name="save_customers" value="1">
        <table class="admin-table">
            <tr><th>ID</th><th>Name</th><th>Business</th><th>Phone</th><th>Email</th><th>Billing</th><th>Shipping</th></tr>
            <?php foreach ($customers as $cust): ?>
                <tr>
                    <td><?php echo $cust['id']; ?></td>
                    <td><input type="text" name="c_name_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['name']); ?>"></td>
                    <td><input type="text" name="c_business_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['business_name']); ?>"></td>
                    <td><input type="text" name="c_phone_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['phone']); ?>"></td>
                    <td><input type="email" name="c_email_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['email']); ?>"></td>
                    <td><input type="text" name="c_bill_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['billing_address']); ?>"></td>
                    <td><input type="text" name="c_ship_<?php echo $cust['id']; ?>" value="<?php echo htmlspecialchars($cust['shipping_address']); ?>"></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
                <tr><td colspan="7">No customers found.</td></tr>
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
            <?php foreach ($products as $prod): ?>
                <tr>
                    <td><?php echo $prod['id']; ?></td>
                    <td><input type="text" name="name_<?php echo $prod['id']; ?>" value="<?php echo htmlspecialchars($prod['name']); ?>"></td>
                    <td><input type="text" name="desc_<?php echo $prod['id']; ?>" value="<?php echo htmlspecialchars($prod['description']); ?>"></td>
                    <td><input type="number" step="0.01" name="price_<?php echo $prod['id']; ?>" value="<?php echo number_format($prod['price'], 2); ?>"></td>
                    <td><a href="?delete_product=<?php echo $prod['id']; ?>">Delete</a></td>
                </tr>
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