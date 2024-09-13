<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Response\JsonResponse;

//use Joomla\CMS\Table\Table;

class CartHelper
{
    protected $db;
    protected $cartId;
    protected $cart;

    public function __construct($cartId = null)
    {
        $this->db = Factory::getDbo();
        //to cart id tha mporouse na nai kai userID kai recognizeKey san duo times oti voleuei
        $this->cartId = $cartId ? $cartId : $this->getOrCreateCartId();
        $this->cart = $this->getCartItems(); // Initialize cart object
    }

    public function addProductsToDatabase($cartId, $productId, $quantity)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $productColumns = array(
            $db->quoteName('id_cart') . ' = ' . $db->quote($cartId),
            $db->quoteName('id_product') . ' = ' . $db->quote($productId),
            $db->quoteName('quantity') . ' = ' . $db->quote($quantity),
            $db->quoteName('date_add') . ' = ' . $db->quote(Factory::getDate()->toSql()),
        );

        $query = $db->getQuery(true);
        $query->insert('#__alfa_cart_product')
            ->set($productColumns);
        $db->setQuery($query);
        $db->execute();
    }

    public function addToCart()
    {
        $app = Factory::getApplication();
        $errorOccured = false;

        $input = $app->input;

        $quantity = $input->getInt('quantity', 1);
        $productId = $input->getInt('product_id', 0);
        $userId = Factory::getApplication()->getIdentity()->id;
        $data = [];

        $cookieName = 'recognize_key';
        $cookieValue = rand();
        $rkCookie = $app->input->cookie->get($cookieName, '');

        if ($rkCookie == '') {
            // Define the cookie parameters
            $expires = time() + 3600 * 24; // Cookie expires in 1 hour
            $path = '/'; // Cookie is available across the entire domain
            $domain = ''; // Leave empty for the current domain
            $secure = true; // Set to true if using HTTPS
            $httponly = true; // Cookie is not accessible via JavaScript
            $samesite = 'Strict'; // Can be 'Strict', 'Lax', or 'None'
            $app->input->cookie->set(
                $cookieName,
                $cookieValue,
                [
                    'expires' => $expires,
                    'path' => $path,
                    'domain' => $domain,
                    'secure' => $secure,
                    'httponly' => $httponly,
                    'samesite' => $samesite
                ]);
            $rkCookie = $app->input->cookie->get($cookieName, $cookieValue);
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $query = $db->getQuery(true);
            $query->select($db->quoteName('id_cart'))
                ->from($db->quoteName('#__alfa_cart'));
            if ($userId > 0) {
                $query->where($db->quoteName('id_customer') . ' = ' . $db->quote($userId));
            } else {
                $query->where($db->quoteName('recognize_key') . ' = ' . $db->quote($rkCookie));
            }
            $db->setQuery($query);
            $cartId = intval($db->loadResult());

            if (!$cartId) {

                $cartObject = new \stdClass();
                $cartObject->id_shop_group = 1;
                $cartObject->id_carrier = 1;
                $cartObject->delivery_option = 'walking';
                $cartObject->id_lang = 1;
                $cartObject->id_address_delivery = 1;
                $cartObject->id_address_invoice = 1;
                $cartObject->id_currency = 1;
                $cartObject->id_customer = $userId;
                $cartObject->date_add = Factory::getDate()->toSql();
                $cartObject->date_upd = Factory::getDate()->toSql();
                $cartObject->recognize_key = $rkCookie;

                $db->insertObject('#__alfa_cart', $cartObject);
                $cartId = $db->insertid();

            }

            $this->addProductsToDatabase($cartId, $productId, $quantity);
            $data['insert_query'] = ['success', $cartId]; //TODO: remove ids from errors

        } catch (Exception $e) {
            $data['error_message'] = $e->getMessage(); //TODO: remove ids from errors
            $errorOccured = true;
        }

        $response = new JsonResponse($data, $errorOccured ? 'Item failed to be added' : 'Item added successfully', $errorOccured);
        echo $response;
        $app->close();
        // $response = new JsonResponse($priceLayout,'Prices return successfully',$errorOccured);
        // echo $productId;
        // echo json_encode('test');
        // exit;
    }

    // Retrieve or create a cart ID
    protected function getOrCreateCartId()
    {
        //to id tou xrhsth h to id tou session an einai guest
    }

    // Add product or update quantity in cart
    public function addOrUpdateProduct($productId, $quantity)
    {
        $productExists = false;
        foreach ($this->cart as &$item) {
            if ($item['product_id'] == $productId) {
                $item['quantity'] = $quantity;
                $productExists = true;
                break;
            }
        }

        if ($productExists) {
            // Update existing product
            $query = $this->db->getQuery(true)
                ->update('#__cart_items')
                ->set('quantity = ' . $this->db->quote($quantity))
                ->where('cart_id = ' . $this->db->quote($this->cartId))
                ->where('product_id = ' . $this->db->quote($productId));
        } else {
            // Add new product
            $query = $this->db->getQuery(true)
                ->insert('#__cart_items')
                ->columns(array('cart_id', 'product_id', 'quantity'))
                ->values($this->db->quote($this->cartId) . ', ' . $this->db->quote($productId) . ', ' . $this->db->quote($quantity));
        }

        $this->db->setQuery($query);
        $this->db->execute();

        // Refresh cart object
        $this->cart = $this->getCartItems();
    }

    // Remove product from cart
    public function removeProduct($productId)
    {
        $query = $this->db->getQuery(true)
            ->delete('#__cart_items')
            ->where('cart_id = ' . $this->db->quote($this->cartId))
            ->where('product_id = ' . $this->db->quote($productId));

        $this->db->setQuery($query);
        $this->db->execute();

        // Refresh cart object
        $this->cart = $this->getCartItems();
    }

    // Clear the cart
    public function clearCart()
    {
        $query = $this->db->getQuery(true)
            ->delete('#__cart_items')
            ->where('cart_id = ' . $this->db->quote($this->cartId));

        $this->db->setQuery($query);
        $this->db->execute();

        // Refresh cart object
        $this->cart = $this->getCartItems();
    }

    // Get all items in the cart
    public function getCartItems()
    {
        $query = $this->db->getQuery(true)
            ->select('product_id, quantity')
            ->from('#__cart_items')
            ->where('cart_id = ' . $this->db->quote($this->cartId));

        $this->db->setQuery($query);
        return $this->db->loadAssocList();
    }

    // Get total price of the cart
    public function getTotal()
    {
        $total = 0;
        $cartItems = $this->getCartItems();

        foreach ($cartItems as $item) {
            $product = $this->getProductDetails($item['product_id']);
            $total += $product->price * $item['quantity'];
        }

        return $total;
    }

    // Check if the cart is empty
    public function isEmpty()
    {
        return count($this->cart) === 0;
    }

    // Get the total number of items in the cart
    public function getCartCount()
    {
        return array_sum(array_column($this->cart, 'quantity'));
    }

    // Fetch product details from the database
    protected function getProductDetails($productId)
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from('#__products')
            ->where('id = ' . $this->db->quote($productId));

        $this->db->setQuery($query);
        $product = $this->db->loadObject();

        if (!$product) {
            throw new RuntimeException('Product not found', 404);
        }

        return $product;
    }
}