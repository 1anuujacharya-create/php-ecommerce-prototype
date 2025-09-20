<?php
/**
 * Author: Anuj Acharya
 * Date: 2025-10-21
 *
 * A tiny, single-file e-commerce prototype for local development.
 * The goal is to demonstrate clean structure, safe defaults, and good intentions:
 *  - Google-style small helpers at the top (pure functions, no side-effects)
 *  - A minimal “router” using a query string to switch views (shop, detail, cart)
 *  - Session cart that survives page loads (good for quick demos)
 *  - Clear comments that explain PURPOSE, not just mechanics
 *
 * This is deliberately simple and framework-free so you can read it end-to-end.
 */

session_start(); // We keep cart & reviews client-side for the demo. No DB required.

/*───────────────────────────────────────────────────────────────────────────────
| Q1. REUSABLE HELPERS
| These tiny functions keep intent obvious and code DRY.
| They avoid touching globals so they’re easy to test in isolation.
───────────────────────────────────────────────────────────────────────────────*/

/**
 * calculateTotal
 * PURPOSE: Tell the shopper the end-to-end cost for a line item the way a receipt does.
 *          We include a predictable sales tax (10%) to avoid “surprise” totals later.
 */
function calculateTotal(float $price, int $quantity): float {
    $subtotal = $price * $quantity;
    $tax      = $subtotal * 0.10; // demo tax; real sites pull tax rules by region
    return round($subtotal + $tax, 2);
}

/**
 * formatProductName
 * PURPOSE: Make names presentable and consistent for UI (capitalization/length).
 *          This avoids layout breaks and keeps cards neat even with messy data.
 */
function formatProductName(string $name): string {
    $normalized = ucwords(strtolower(trim($name)));
    return (strlen($normalized) > 50) ? substr($normalized, 0, 50) : $normalized;
}

/**
 * calculateDiscount
 * PURPOSE: Encapsulate promo math so we don’t re-implement it in multiple views.
 */
function calculateDiscount(float $price, float $discountPercent): float {
    $discountAmount = $price * ($discountPercent / 100.0);
    return round($price - $discountAmount, 2);
}

/**
 * sanitizeProductName
 * PURPOSE: Defensive layer for any user-visible strings from unknown sources.
 *          The shop is seeded by us, but showing intent makes later DB work safer.
 */
function sanitizeProductName(string $raw): string {
    return preg_replace("/[^a-zA-Z0-9\s]/", "", $raw);
}

/**
 * formatDescription
 * PURPOSE: Normalize product descriptions so they look written by one voice.
 *          (Real apps would store “marketing copy”; here we normalize seed data.)
 */
function formatDescription(string $desc): string {
    return trim(strtolower(str_replace("_", " ", $desc)));
}

/**
 * Simple cart total calculator (subtotal, tax, grand)
 * PURPOSE: One source of truth for money math; UI reads from here.
 */
function computeCartTotals(): array {
    $subtotal = 0.0;
    foreach ($_SESSION['cart'] ?? [] as $ci) {
        $subtotal += $ci['price'] * $ci['quantity'];
    }
    $tax   = round($subtotal * 0.10, 2);
    $total = round($subtotal + $tax, 2);
    return ['subtotal'=>round($subtotal,2), 'tax'=>$tax, 'total'=>$total];
}

/** Tiny star renderer for reviews (kept here to avoid template noise) */
function renderStars(int $n): string {
    $n = max(0, min(5, $n));
    return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}

/*───────────────────────────────────────────────────────────────────────────────
| Q2. DATA MODEL (IN-MEMORY)
| PURPOSE: Seed the shop with believable data & real images without a database.
───────────────────────────────────────────────────────────────────────────────*/

$products = [
    [
        'id'=>101,
        'name'=>'Laptop Pro 15',
        'category'=>'Electronics',
        'price'=>1299.99,
        'description'=>'A high-performance 15-inch laptop with 16GB RAM, 512GB SSD, color-accurate display, and quiet thermals. Great for creators, developers, and power users.',
        'image'=>'images/laptop.jpg'
    ],
    [
        'id'=>102,
        'name'=>'Smartphone X',
        'category'=>'Electronics',
        'price'=>899.50,
        'description'=>'Edge-to-edge OLED, advanced multi-lens camera, all-day battery, and flagship performance. Ships unlocked and works with major carriers.',
        'image'=>'images/smartphone.jpg'
    ],
    [
        'id'=>103,
        'name'=>'Noise Cancelling Headphones',
        'category'=>'Electronics',
        'price'=>199.00,
        'description'=>'Wireless over-ear headphones with active noise cancellation, 30-hour battery life, USB-C fast charging, and plush memory-foam comfort.',
        'image'=>'images/headphones.jpg'
    ],
    [
        'id'=>104,
        'name'=>'Genuine Leather Wallet',
        'category'=>'Fashion',
        'price'=>74.95,
        'description'=>'Handcrafted from full-grain leather with RFID protection. Holds 8 cards and bills while staying slim in the pocket. Ages beautifully.',
        'image'=>'images/wallet.jpg'
    ],
    [
        'id'=>105,
        'name'=>'4K Smart TV 55\"',
        'category'=>'Electronics',
        'price'=>699.00,
        'description'=>'55-inch 4K Ultra HD with HDR10, slim bezels, and a fast Smart TV OS. Stream your favorites and auto-calibrate picture with one tap.',
        'image'=>'images/tv.jpg'
    ],
    // Intentional duplicate to show dedupe logic
    [
        'id'=>101,
        'name'=>'Laptop Pro 15',
        'category'=>'Electronics',
        'price'=>1299.99,
        'description'=>'Duplicate entry that will be removed by dedupe (simulating noisy feeds).',
        'image'=>'image/laptop.jpg'
    ],
];

/** PURPOSE: Data feeds are messy. We dedupe by ID to avoid showing duplicates. */
function removeDuplicatesById(array $items): array {
    $seen = [];
    $out  = [];
    foreach ($items as $it) {
        if (!in_array($it['id'], $seen, true)) {
            $seen[] = $it['id'];
            $out[]  = $it;
        }
    }
    return $out;
}
$products = removeDuplicatesById($products);

/** PURPOSE: Sort consistently so the UI looks intentional (price ascending). */
usort($products, fn($a,$b) => $a['price'] <=> $b['price']);

/** PURPOSE: Seasonal merchandising—show a “what you’d pay” price without mutating originals. */
$discountedProducts = array_map(function($p){
    $p['discounted_price'] = ($p['category'] === 'Electronics')
        ? calculateDiscount((float)$p['price'], 10.0) // 10% promo
        : (float)$p['price'];
    return $p;
}, $products);

/*───────────────────────────────────────────────────────────────────────────────
| Q3. SESSION STATE (CART + REVIEWS)
| PURPOSE: Keep demo friction-free—no DB, but still interactive.
|          We isolate mutations inside the POST handler below.
───────────────────────────────────────────────────────────────────────────────*/
$_SESSION['cart']    = $_SESSION['cart']    ?? []; // [['id','name','price','quantity']]
$_SESSION['reviews'] = $_SESSION['reviews'] ?? []; // per-product: [pid => [ ['author','rating','text'], ... ]]

/*───────────────────────────────────────────────────────────────────────────────
| Q4. POST HANDLER (ADD TO CART, UPDATE CART, CLEAR CART, ADD REVIEW)
| PURPOSE: Centralize all write actions; redirect after POST (PRG pattern)
|          so refreshing doesn’t double-submit forms.
───────────────────────────────────────────────────────────────────────────────*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_to_cart') {
        // PURPOSE: Treat cart as append-only set keyed by ID (merge quantities).
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        foreach ($products as $p) {
            if ($p['id'] === $pid) {
                $found = false;
                foreach ($_SESSION['cart'] as &$ci) {
                    if ($ci['id'] === $pid) { $ci['quantity'] += $qty; $found = true; break; }
                }
                if (!$found) {
                    $_SESSION['cart'][] = ['id'=>$p['id'], 'name'=>$p['name'], 'price'=>(float)$p['price'], 'quantity'=>$qty];
                }
                break;
            }
        }
        header("Location: ".$_SERVER['PHP_SELF']."?view=cart"); exit;

    } elseif ($action === 'update_cart') {
        // PURPOSE: Let shoppers correct quantities inline; 0 means remove.
        $qtys = $_POST['cart_qty'] ?? [];
        foreach ($_SESSION['cart'] as $i => $ci) {
            $newQty = max(0, (int)($qtys[$i] ?? $ci['quantity']));
            if ($newQty === 0) unset($_SESSION['cart'][$i]);
            else $_SESSION['cart'][$i]['quantity'] = $newQty;
        }
        $_SESSION['cart'] = array_values($_SESSION['cart']); // reindex after unsets
        header("Location: ".$_SERVER['PHP_SELF']."?view=cart"); exit;

    } elseif ($action === 'clear_cart') {
        // PURPOSE: Quick escape hatch for demos when cart gets messy.
        $_SESSION['cart'] = [];
        header("Location: ".$_SERVER['PHP_SELF']."?view=cart"); exit;

    } elseif ($action === 'add_review') {
        // PURPOSE: Capture lightweight social proof; keep it per-product.
        $pid    = (int)($_POST['product_id'] ?? 0);
        $author = trim($_POST['author'] ?? 'Anonymous');
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $text   = trim($_POST['text'] ?? '');
        if ($pid && $text !== '') {
            $_SESSION['reviews'][$pid][] = [
                'author' => htmlspecialchars($author, ENT_QUOTES, 'UTF-8'), // defensive
                'rating' => $rating,
                'text'   => htmlspecialchars($text,   ENT_QUOTES, 'UTF-8')
            ];
        }
        header("Location: ".$_SERVER['PHP_SELF']."?view=detail&id=".$pid); exit;
    }
}

/*───────────────────────────────────────────────────────────────────────────────
| Q5. MINI ROUTER
| PURPOSE: Avoid frameworks while keeping templates simple and intentional.
───────────────────────────────────────────────────────────────────────────────*/
$view = $_GET['view'] ?? 'shop';
$pid  = isset($_GET['id']) ? (int)$_GET['id'] : null;

/* A tiny finder so both cart and detail view can resolve products the same way. */
function findProductById(array $products, int $id): ?array {
    foreach ($products as $p) if ($p['id'] === $id) return $p;
    return null;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My Online Shop</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--accent:#0d6efd;--bg:#f7f9fc;--card:#fff;--muted:#667085;}
*{box-sizing:border-box}
body{font-family:Inter,system-ui,"Segoe UI",Arial,sans-serif;margin:0;background:var(--bg);color:#101828;}
header{background:linear-gradient(90deg,#0d6efd,#6610f2);color:#fff;padding:18px 24px;}
header .container{display:flex;justify-content:space-between;align-items:center;max-width:1100px;margin:0 auto;}
header h1{margin:0;font-size:1.25rem;}
nav a{color:rgba(255,255,255,.95);text-decoration:none;margin-left:14px;font-weight:600;}
.container{max-width:1100px;margin:22px auto;padding:0 20px;}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;}
.card{background:var(--card);border-radius:12px;padding:12px;box-shadow:0 8px 20px rgba(13,110,253,0.06);}
.card img{width:100%;height:160px;object-fit:cover;border-radius:8px;}
.card a{color:inherit;text-decoration:none}
.price{color:var(--accent);font-weight:800;font-size:1.05rem;}
.muted{color:var(--muted);font-size:.95rem;}
.btn{background:var(--accent);color:white;padding:8px 12px;border-radius:8px;border:none;cursor:pointer;}
aside{width:340px;}
.summary{background:var(--card);padding:14px;border-radius:10px;box-shadow:0 6px 12px rgba(0,0,0,0.04);}
footer{max-width:1100px;margin:30px auto;color:var(--muted);padding:16px 20px;display:flex;justify-content:space-between;align-items:center;}
.note{font-size:.9rem;color:#b02a37;margin-top:8px;}
.reviews .review{background:linear-gradient(180deg,#fff,#fbfdff);padding:12px;border-radius:10px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:8px;text-align:left;border-bottom:1px solid #eef2ff;}
input,select,textarea{font:inherit;padding:8px;border:1px solid #d0d5dd;border-radius:8px;width:100%;}
label{display:block;margin:10px 0 6px 0;}
@media(max-width:880px){aside{display:none}}
</style>
</head>
<body>
<header>
  <div class="container">
    <div>
      <h1>My Online Shop</h1>
      <div style="font-size:.9rem;opacity:.9">Quality products • Simple checkout • Prototype</div>
    </div>
    <nav>
      <a href="<?= $_SERVER['PHP_SELF'] ?>">Shop</a>
      <a href="<?= $_SERVER['PHP_SELF'] ?>?view=cart">Cart (<?= array_sum(array_column($_SESSION['cart'] ?? [], 'quantity')) ?: 0 ?>)</a>
    </nav>
  </div>
</header>

<div class="container" style="display:flex;gap:18px;">
<?php if ($view === 'shop'): ?>

  <main style="flex:1;">
    <h2>Available Products</h2>
    <p class="muted">Click a product to view details, long description, and customer reviews.</p>

    <div class="grid" style="margin-top:14px;">
      <?php foreach ($products as $p): ?>
        <?php
          $cleanName   = formatProductName(sanitizeProductName($p['name']));
          $desc        = htmlspecialchars(ucfirst(formatDescription($p['description'])));
          $displayUrl  = $_SERVER['PHP_SELF']."?view=detail&id=".$p['id']; // link to detail
          $displayPrice= number_format($p['price'], 2);
          $discounted  = ($p['category'] === 'Electronics') ? number_format(calculateDiscount((float)$p['price'], 10.0),2) : null;
        ?>
        <div class="card">
          <a href="<?= $displayUrl ?>">
            <img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($cleanName) ?>">
          </a>
          <h3 style="margin:.6rem 0;">
            <a href="<?= $displayUrl ?>"><?= htmlspecialchars($cleanName) ?></a>
          </h3>
          <div class="muted"><?= $desc ?></div>
          <div style="margin-top:8px;">
            <?php if ($discounted !== null): ?>
              <div class="price">$<?= $discounted ?> <span style="text-decoration:line-through;color:#999;font-weight:600;margin-left:8px;">$<?= $displayPrice ?></span></div>
            <?php else: ?>
              <div class="price">$<?= $displayPrice ?></div>
            <?php endif; ?>
          </div>
          <form method="post" style="margin-top:10px;">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="number" name="quantity" value="1" min="1" style="width:64px">
            <input type="hidden" name="action" value="add_to_cart">
            <button class="btn" type="submit" style="margin-left:8px;">Add to Cart</button>
          </form>
          <div style="margin-top:10px;" class="muted">Category: <?= htmlspecialchars($p['category']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <hr style="margin:24px 0;border:none;height:1px;background:#eef4ff">

    <h3>Discounted Electronics (Seasonal -10%)</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr));">
      <?php foreach ($discountedProducts as $dp): ?>
        <div class="card">
          <a href="<?= $_SERVER['PHP_SELF'] ?>?view=detail&id=<?= (int)$dp['id'] ?>">
            <img src="<?= htmlspecialchars($dp['image']) ?>" alt="<?= htmlspecialchars(formatProductName($dp['name'])) ?>">
          </a>
          <h4 style="margin:.6rem 0;">
            <a href="<?= $_SERVER['PHP_SELF'] ?>?view=detail&id=<?= (int)$dp['id'] ?>">
              <?= htmlspecialchars(formatProductName($dp['name'])) ?>
            </a>
          </h4>
          <div class="muted"><?= htmlspecialchars(ucfirst(formatDescription($dp['description']))) ?></div>
          <div style="margin-top:8px;" class="price">$<?= number_format($dp['discounted_price'],2) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:18px;"><div class="note">This is a prototype and not for production</div></div>
  </main>

  <aside>
    <div class="summary">
      <h3>Your Cart</h3>
      <?php if (empty($_SESSION['cart'])): ?>
        <p class="muted">No items yet — add products to your cart.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($_SESSION['cart'] as $ci): ?>
            <li><?= htmlspecialchars(formatProductName($ci['name'])) ?> × <?= (int)$ci['quantity'] ?> — $<?= number_format($ci['price'] * $ci['quantity'], 2) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php $tot = computeCartTotals(); ?>
        <p class="muted">Subtotal: $<?= number_format($tot['subtotal'],2) ?></p>
        <p class="muted">Tax (10%): $<?= number_format($tot['tax'],2) ?></p>
        <p style="font-weight:800;">Total: $<?= number_format($tot['total'],2) ?></p>
        <div style="margin-top:10px;">
          <a href="<?= $_SERVER['PHP_SELF'] ?>?view=cart" class="btn">View Cart & Checkout</a>
        </div>
      <?php endif; ?>
    </div>
  </aside>

<?php elseif ($view === 'detail' && $pid):
      $product = findProductById($products, $pid); ?>

  <main style="flex:1;">
    <?php if (!$product): ?>
      <p>Product not found. <a href="<?= $_SERVER['PHP_SELF'] ?>">Return to shop</a></p>
    <?php else: ?>
      <div class="card" style="padding:18px;">
        <h2><?= htmlspecialchars($product['name']) ?></h2>
        <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" style="max-width:520px;border-radius:12px;">
        <p class="muted" style="margin-top:10px;"><?= htmlspecialchars($product['description']) ?></p>
        <p class="price" style="margin:10px 0 0 0;">$<?= number_format($product['price'],2) ?></p>

        <form method="post" style="margin:12px 0;">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <input type="number" name="quantity" value="1" min="1" style="width:64px;">
          <input type="hidden" name="action" value="add_to_cart">
          <button class="btn" type="submit">Add to Cart</button>
          <a class="btn" href="<?= $_SERVER['PHP_SELF'] ?>" style="background:#6c757d;margin-left:8px;">Back to Shop</a>
        </form>
      </div>

      <div class="card" style="margin-top:16px;">
        <h3>Customer Reviews</h3>
        <div class="reviews">
          <?php foreach (($_SESSION['reviews'][$pid] ?? []) as $r): ?>
            <div class="review">
              <div><strong><?= $r['author'] ?></strong> <span style="color:#f59e0b;margin-left:8px;"><?= renderStars($r['rating']) ?></span></div>
              <div class="muted" style="margin-top:6px;"><?= $r['text'] ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($_SESSION['reviews'][$pid])): ?>
            <p class="muted">No reviews yet — be the first to share your experience.</p>
          <?php endif; ?>
        </div>

        <h4>Leave a Review</h4>
        <!-- PURPOSE: Keep form tiny and approachable; friction kills feedback. -->
        <form method="post" style="max-width:520px;">
          <input type="hidden" name="action" value="add_review">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <label for="author">Your name</label>
          <input id="author" name="author" type="text" placeholder="Jane Doe" required>
          <label for="rating">Rating</label>
          <select id="rating" name="rating">
            <?php for ($i=5; $i>=1; $i--): ?>
              <option value="<?= $i ?>"><?= $i ?> — <?= renderStars($i) ?></option>
            <?php endfor; ?>
          </select>
          <label for="text">Your review</label>
          <textarea id="text" name="text" rows="3" placeholder="What did you like? What could be better?" required></textarea>
          <button class="btn" type="submit" style="margin-top:10px;">Submit Review</button>
        </form>
      </div>
    <?php endif; ?>
  </main>

<?php elseif ($view === 'cart'): ?>

  <main style="flex:1;">
    <h2>Shopping Cart</h2>
    <?php if (empty($_SESSION['cart'])): ?>
      <p>Your cart is empty. <a href="<?= $_SERVER['PHP_SELF'] ?>">Continue shopping</a></p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="update_cart">
        <table>
          <thead><tr><th>Product</th><th>Unit Price</th><th>Qty</th><th>Line (pre-tax)</th><th>Line (incl. tax)</th></tr></thead>
          <tbody>
            <?php foreach ($_SESSION['cart'] as $idx => $ci): ?>
              <tr>
                <td><?= htmlspecialchars(formatProductName($ci['name'])) ?></td>
                <td>$<?= number_format($ci['price'],2) ?></td>
                <td>
                  <input type="number" name="cart_qty[]" value="<?= (int)$ci['quantity'] ?>" min="0" style="width:70px;">
                </td>
                <td>$<?= number_format($ci['price'] * $ci['quantity'],2) ?></td>
                <td>$<?= number_format(calculateTotal($ci['price'], $ci['quantity']),2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn" type="submit">Update Cart</button>
          <button class="btn" type="submit" name="action" value="clear_cart" style="background:#6c757d">Clear Cart</button>
          <a class="btn" href="<?= $_SERVER['PHP_SELF'] ?>" style="background:#28a745">Continue Shopping</a>
        </div>
      </form>

      <?php $tot = computeCartTotals(); ?>
      <div class="summary" style="margin-top:16px;">
        <h3>Order Summary</h3>
        <p>Subtotal: $<?= number_format($tot['subtotal'],2) ?></p>
        <p>Tax (10%): $<?= number_format($tot['tax'],2) ?></p>
        <p style="font-weight:900;">Grand Total: $<?= number_format($tot['total'],2) ?></p>
        <div class="muted" style="margin-top:8px">This demo does not process payments.</div>
      </div>
    <?php endif; ?>
  </main>

<?php endif; ?>
</div>

<footer>
  <div>
    <div style="font-weight:700">@ My Online Shop 2025</div>
    <div class="muted">Prototype only — not for production</div>
  </div>
  <div class="muted">Built with PHP, HTML, and CSS</div>
</footer>

<?php

/*───────────────────────────────────────────────────────────────────────────────
| Q6. EXTRA REQUIREMENTS (REPORTS BLOCK)
| PURPOSE: Show array merge, description analysis, and review processing results.
|          Kept outside main shop flow to avoid altering my existing code.
───────────────────────────────────────────────────────────────────────────────*/

/**
 * mergeSupplierInventories
 * PURPOSE: Merge products from two suppliers, remove duplicates by ID, return final array.
 */
function mergeSupplierInventories(array $supplierA, array $supplierB): array {
    $combined = array_merge($supplierA, $supplierB);
    return removeDuplicatesById($combined);
}

/**
 * analyzeProductDescription
 * PURPOSE: Count characters/words in a product description, check for keyword "leather".
 */
function analyzeProductDescription(string $description): void {
    $charCount = strlen($description);
    $wordCount = str_word_count($description);
    $keywordFound = stripos($description, "leather") !== false ? "Keyword found" : "Keyword not found";

    echo "<div style='background:#f9fafb;padding:12px;border-radius:8px;margin-bottom:16px;'>
            <p><strong>Total Characters:</strong> {$charCount}</p>
            <p><strong>Total Words:</strong> {$wordCount}</p>
            <p><strong>Keyword Check:</strong> {$keywordFound}</p>
          </div>";
}

/**
 * processCustomerReview
 * PURPOSE: Preview review text, search for word 'excellent', append thank-you note.
 */
function processCustomerReview(string $review): void {
    $preview = substr($review, 0, 20) . "...";
    $pos = stripos($review, "excellent");
    $positionMsg = ($pos !== false) ? "Word 'excellent' found at position: {$pos}" : "Word 'excellent' not found";
    $updatedReview = $review . " Thank you for your feedback!";

    echo "<div style='background:#f9fafb;padding:12px;border-radius:8px;'>
            <p><strong>Preview:</strong> {$preview}</p>
            <p><strong>{$positionMsg}</strong></p>
            <p><strong>Updated Review:</strong> {$updatedReview}</p>
          </div>";
}

/*───────────────────────────────────────────────────────────────────────────────
| REPORTS OUTPUT SECTION
───────────────────────────────────────────────────────────────────────────────*/
if (isset($_GET['reports']) && $_GET['reports'] === 'extra') {
    echo "<div style='max-width:1100px;margin:30px auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.05);font-family:Arial,sans-serif;'>";

    // Supplier merge reports
    $supplierA = [
        ['id'=>201,'name'=>'USB Cable','category'=>'Electronics','price'=>9.99],
        ['id'=>202,'name'=>'Leather Belt','category'=>'Fashion','price'=>39.99]
    ];
    $supplierB = [
        ['id'=>202,'name'=>'Leather Belt','category'=>'Fashion','price'=>39.99], // duplicate
        ['id'=>203,'name'=>'Wireless Mouse','category'=>'Electronics','price'=>29.99]
    ];
    $merged = mergeSupplierInventories($supplierA, $supplierB);

    echo "<h2 style='color:#0d6efd;margin-bottom:10px;'>Merged Supplier Inventory</h2>";
    echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse:collapse;width:100%;margin-bottom:20px;'>";
    echo "<tr style='background:#f0f4ff;'><th>ID</th><th>Name</th><th>Category</th><th>Price ($)</th></tr>";
    foreach ($merged as $item) {
        echo "<tr>
                <td>{$item['id']}</td>
                <td>{$item['name']}</td>
                <td>{$item['category']}</td>
                <td>".number_format($item['price'],2)."</td>
              </tr>";
    }
    echo "</table>";

    // Product description analysis reports
    $demoDescription = "This is a high-quality leather wallet with RFID protection.";
    echo "<h2 style='color:#0d6efd;margin-bottom:10px;'>Description Analysis</h2>";
    analyzeProductDescription($demoDescription);

    // Customer review string processing reports
    $reportsReview = "Great product! Fast delivery and excellent service.";
    echo "<h2 style='color:#0d6efd;margin-bottom:10px;'>Review Processing</h2>";
    processCustomerReview($reportsReview);

    echo "</div>";
}
?>

<?php /* Non-invasive: add "Reports" link to the header nav via JS */ ?>
<script>
(function () {
  var nav = document.querySelector('header nav');
  if (!nav) return;

  // Avoid adding twice if it's already there
  var already = Array.prototype.slice.call(nav.querySelectorAll('a'))
    .some(function (a) { return (a.getAttribute('href') || '').indexOf('?reports=extra') !== -1; });
  if (already) return;

  var reportsLink = document.createElement('a');
  reportsLink.href = "<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>?reports=extra";
  reportsLink.textContent = "Reports";
  reportsLink.title = "Show extra requirement reports";
  // Inherit the existing nav <a> styling; no classes needed.
  nav.appendChild(reportsLink);
})();
</script>

</body>
</html>
?>
