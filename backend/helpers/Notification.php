<?php
require_once __DIR__ . '/../models/Notification.php';

class NotificationHelper {
    private $notificationModel;
    
    public function __construct() {
        $this->notificationModel = new Notification();
    }
    
    public function sendEmailNotification($to, $subject, $template, $data = []) {
        // Email configuration
        $config = [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'noreply@shopnexia.com',
            'password' => 'your_password',
            'from' => 'ShopNexia <noreply@shopnexia.com>'
        ];
        
        // Prepare email content
        $body = $this->renderTemplate($template, $data);
        
        // Send email using PHP mailer or similar
        return $this->sendEmail($to, $subject, $body, $config);
    }
    
    public function sendSMSNotification($phone, $message) {
        // SMS gateway configuration (example using Twilio or local gateway)
        $config = [
            'provider' => 'twilio',
            'account_sid' => 'your_sid',
            'auth_token' => 'your_token',
            'from_number' => '+1234567890'
        ];
        
        // Send SMS
        return $this->sendSMS($phone, $message, $config);
    }
    
    public function sendPushNotification($deviceTokens, $title, $message, $data = []) {
        // Firebase Cloud Messaging configuration
        $config = [
            'server_key' => 'your_firebase_server_key',
            'url' => 'https://fcm.googleapis.com/fcm/send'
        ];
        
        if (!is_array($deviceTokens)) {
            $deviceTokens = [$deviceTokens];
        }
        
        $payload = [
            'registration_ids' => $deviceTokens,
            'notification' => [
                'title' => $title,
                'body' => $message,
                'sound' => 'default'
            ],
            'data' => $data
        ];
        
        return $this->sendFCMNotification($payload, $config);
    }
    
    public function sendOrderConfirmation($orderId, $userId) {
        // Get order details
        $orderModel = new Order();
        $order = $orderModel->getOrderDetails($orderId, $userId);
        
        if (!$order) {
            return false;
        }
        
        // Send email
        $emailData = [
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'] ?? 'Customer',
            'order_date' => $order['created_at'],
            'total_amount' => $order['total_amount'],
            'items' => $order['items'],
            'shipping_address' => $this->formatAddress($order)
        ];
        
        $this->sendEmailNotification(
            $order['customer_email'],
            'Order Confirmation - #' . $order['order_number'],
            'order_confirmation',
            $emailData
        );
        
        // Send SMS
        $smsMessage = "Your order #{$order['order_number']} has been confirmed. Total: ৳{$order['total_amount']}. Thank you!";
        $this->sendSMSNotification($order['customer_phone'], $smsMessage);
        
        // Send in-app notification
        $this->notificationModel->sendOrderNotification(
            $userId,
            $orderId,
            'confirmed',
            'Your order has been confirmed and is being processed.'
        );
        
        return true;
    }
    
    public function sendPaymentConfirmation($paymentId, $userId) {
        // Get payment details
        $paymentModel = new Payment();
        $payment = $paymentModel->getById($paymentId);
        
        if (!$payment) {
            return false;
        }
        
        // Send notification
        $this->notificationModel->sendPaymentNotification(
            $userId,
            $paymentId,
            'completed',
            'Your payment has been verified successfully.'
        );
        
        return true;
    }
    
    public function sendShippingUpdate($orderId, $userId, $status, $trackingInfo = null) {
        $statusMessages = [
            'shipped' => 'Your order has been shipped.',
            'delivered' => 'Your order has been delivered.',
            'failed' => 'There was an issue with your delivery.'
        ];
        
        $message = $statusMessages[$status] ?? 'Your order status has been updated.';
        
        if ($trackingInfo) {
            $message .= " Tracking: {$trackingInfo}";
        }
        
        $this->notificationModel->sendOrderNotification($userId, $orderId, $status, $message);
        
        return true;
    }
    
    public function sendPromotionalCampaign($userIds, $campaignData) {
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        
        foreach ($userIds as $userId) {
            // Get user details
            $userModel = new User();
            $user = $userModel->find($userId);
            
            if ($user) {
                // Send email
                $this->sendEmailNotification(
                    $user['email'],
                    $campaignData['subject'],
                    'promotional',
                    array_merge($campaignData, ['name' => $user['name']])
                );
                
                // Send in-app notification
                $this->notificationModel->sendPromotionalNotification(
                    $userId,
                    $campaignData['subject'],
                    $campaignData['message'],
                    $campaignData
                );
            }
        }
        
        return true;
    }
    
    private function renderTemplate($template, $data) {
        $templatePath = __DIR__ . "/../templates/email/{$template}.html";
        
        if (!file_exists($templatePath)) {
            return $this->getDefaultTemplate($template, $data);
        }
        
        $content = file_get_contents($templatePath);
        
        // Replace placeholders
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $content = str_replace("{{{$key}}}", $value, $content);
        }
        
        return $content;
    }
    
    private function getDefaultTemplate($template, $data) {
        // Return simple HTML template
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4CAF50; color: white; padding: 10px; text-align: center; }
                .content { padding: 20px; border: 1px solid #ddd; }
                .footer { text-align: center; padding: 10px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>ShopNexia</h1>
                </div>
                <div class='content'>
                    " . json_encode($data) . "
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " ShopNexia. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    private function formatAddress($order) {
        $address = $order['address_line1'];
        
        if (!empty($order['address_line2'])) {
            $address .= ', ' . $order['address_line2'];
        }
        
        $address .= ', ' . $order['city'];
        $address .= ', ' . $order['division'];
        
        if (!empty($order['zip_code'])) {
            $address .= ' - ' . $order['zip_code'];
        }
        
        $address .= ', ' . $order['country'];
        
        return $address;
    }
    
    private function sendEmail($to, $subject, $body, $config) {
        // Implement email sending logic
        // Using PHPMailer or similar
        return true;
    }
    
    private function sendSMS($phone, $message, $config) {
        // Implement SMS sending logic
        return true;
    }
    
    private function sendFCMNotification($payload, $config) {
        // Implement FCM sending logic
        return true;
    }
}
?>