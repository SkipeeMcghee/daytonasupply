<?php
// Manager Portal for Daytona Supply
// This page allows administrators to view and manage orders, customers and products.

session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// -----------------------------------------------------------------------------
// Authentication
// -----------------------------------------------------------------------------

// If the admin is not logged in, show the login form and handle authentication
if (!isset($_SESSION['admin'])) {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = trim($_POST['password'] ?? '');
        $stmt = getDb()->query('SELECT password_hash FROM admin LIMIT 1');
        $hash = $stmt->fetchColumn();
        if ($hash && password_verify($password, $hash)) {
            $_SESSION['admin'] = true;
            header('Location: managerportal.php');
            exit;
        }
        $loginError = 'Incorrect password.';
    }
    $title = 'Office Manager Login';
    include __DIR__ . '/includes/header.php';
    ?>
    <h1>Office Manager Login</h1>
    <?php if (!empty($loginError)): ?>
        <p style="color:red;"><?= htmlspecialchars($loginError) ?></p>
    <?php endif; ?>
    <form method="post" action="managerportal.php">
        <label>Password: <input type="password" name="password" required></label>
        <button type="submit">Login</button>
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    return;
}

// -----------------------------------------------------------------------------
// Handle actions and form submissions
// -----------------------------------------------------------------------------

// Approve or reject an order via query params
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
// Delete a product
if (isset($_GET['delete_product'])) {
    $prodId = (int)$_GET['delete_product'];
    deleteProduct($prodId);
    header('Location: managerportal.php');
    exit;
}

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update existing products
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
        header('Location: managerportal.php');
        exit;
    }
}

// -----------------------------------------------------------------------------
// Fetch data for display
// -----------------------------------------------------------------------------

$orders = getAllOrders();
$customers = getAllCustomers();
$products = getAllProducts();

// -----------------------------------------------------------------------------
// Render portal
// -----------------------------------------------------------------------------

$title = 'Manager Portal';
include __DIR__ . '/includes/header.php';
?>
<h1>Manager Portal</h1>

<h2>Orders</h2>
<?php if (empty($orders)): ?>
    <p>No orders found.</p>
<?php else: ?>
    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Total</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($orders as $order): ?>
            <?php $items = getOrderItems($order['id']); ?>
            <?php $customer = getCustomerById((int)$order['customer_id']); ?>
            <tr>
                <td><?= (int)$order['id'] ?></td>
                <td><?= htmlspecialchars($order['created_at']) ?></td>
                <td><?= htmlspecialchars($customer['name'] ?? 'Unknown') ?></td>
                <td><?= count($items) ?></td>
                <td>$<?= number_format($order['total'], 2) ?></td>
                <td><?= htmlspecialchars($order['status']) ?></td>
                <td>
                    <?php if ($order['status'] === 'Pending'): ?>
                        <a href="managerportal.php?approve_order=<?= (int)$order['id'] ?>" onclick="return confirm('Approve this order?');">Approve</a> |
                        <a href="managerportal.php?reject_order=<?= (int)$order['id'] ?>" onclick="return confirm('Reject this order?');">Reject</a>
                    <?php else: ?>
                        –
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Customers</h2>
<form method="post" action="managerportal.php">
    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <th>Name</th>
            <th>Business</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Billing Address</th>
            <th>Shipping Address</th>
        </tr>
        <?php foreach ($customers as $cust): ?>
            <tr>
                <td><input type="text" name="c_name_<?= (int)$cust['id'] ?>" value="<?= htmlspecialchars($cust['name']) ?>"></td>
                <td><input type="text" name="c_business_<?= (int)$cust['id'] ?>" value="<?= htmlspecialchars($cust['business_name']) ?>"></td>
                <td><input type="text" name="c_phone_<?= (int)$cust['id'] ?>" value="<?= htmlspecialchars($cust['phone']) ?>"></td>
                <td><input type="email" name="c_email_<?= (int)$cust['id'] ?>" value="<?= htmlspecialchars($cust['email']) ?>"></td>
                <td><input type="text" name="c_bill_<?= (int)$cust['id'] ?>" value="<?= htmlspecialchars($cust['billing_address']) ?>"></td>
                <td><input type="text" name="c_ship_<?= (int)$cust['id'] ?>" value="<?= htmlspecialchars($cust['shipping_address']) ?>"></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <button type="submit" name="save_customers">Save Customer Changes</button>
</form>

<h2>Products</h2>
<form method="post" action="managerportal.php">
    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <th>Name</th>
            <th>Description</th>
            <th>Price</th>
            <th>Delete</th>
        </tr>
        <?php foreach ($products as $prod): ?>
            <tr>
                <td><input type="text" name="name_<?= (int)$prod['id'] ?>" value="<?= htmlspecialchars($prod['name']) ?>"></td>
                <td><input type="text" name="desc_<?= (int)$prod['id'] ?>" value="<?= htmlspecialchars($prod['description']) ?>"></td>
                <td><input type="number" step="0.01" name="price_<?= (int)$prod['id'] ?>" value="<?= number_format($prod['price'], 2, '.', '') ?>"></td>
                <td><a href="managerportal.php?delete_product=<?= (int)$prod['id'] ?>" onclick="return confirm('Delete this product?');">Delete</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <button type="submit" name="save_products">Save Product Changes</button>
</form>

<h3>Add New Product</h3>
<form method="post" action="managerportal.php">
    <label>Name: <input type="text" name="name" required></label><br>
    <label>Description: <input type="text" name="description"></label><br>
    <label>Price: <input type="number" step="0.01" name="price" required></label><br>
    <button type="submit" name="add_product">Add Product</button>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>