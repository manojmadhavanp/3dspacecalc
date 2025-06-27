<?php
$pageTitle = "Pricing";
require_once 'templates/header.php';
?>

<h2 class="page-title">Our Pricing Plans</h2>
<p class="text-center">Choose the plan that's right for your business. All new accounts start with a 7-day free trial of the Basic plan features!</p>

<style>
    .pricing-table {
        display: flex;
        justify-content: space-around;
        flex-wrap: wrap;
        margin-top: 30px;
    }
    .pricing-plan {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin: 10px;
        width: 300px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        text-align: center;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .pricing-plan h3 {
        color: #007bff;
        font-size: 1.8rem;
    }
    .pricing-plan .price {
        font-size: 2.5rem;
        font-weight: bold;
        margin: 10px 0;
    }
    .pricing-plan .price_old {
        font-size: 1.5rem;
        font-weight: bold;
        margin: 10px 0;
        text-decoration: line-through;
    }
    .pricing-plan .price_discount {
        font-size: 0.8rem;
        font-weight: bold;
        margin: 0px 0;
        color: red;
    }
    .pricing-plan .price span {
        font-size: 1rem;
        color: #777;
    }
    .pricing-plan ul {
        list-style: none;
        padding: 0;
        margin: 20px 0;
        text-align: left;
    }
    .pricing-plan ul li {
        padding: 8px 0;
        border-bottom: 1px solid #eee;
    }
    .pricing-plan ul li:last-child {
        border-bottom: none;
    }
    .pricing-plan .cta-button {
        background-color: #28a745;
        color: white;
        padding: 12px 20px;
        text-decoration: none;
        border-radius: 5px;
        display: inline-block;
        margin-top: auto; /* Pushes button to bottom */
    }
    .pricing-plan .cta-button:hover {
        background-color: #218838;
    }
    .pricing-plan.popular {
        border-top: 5px solid #ffc107; /* Highlight popular plan */
    }
</style>

<div class="pricing-table">
    <div class="pricing-plan">
        <div>
            <h3>Trial</h3>
            <div class="price">Free</div>
            <p>7-Day Full Access Trial</p>
            <ul>
                <li>1 User Account</li>
                <li>1 Calculation per Day</li>
                <li>3D Visualization</li>
                <li>Basic Client Management</li>
                <li>Standard Support</li>
            </ul>
        </div>
        <a href="register.php" class="cta-button">Start Free Trial</a>
    </div>

    <div class="pricing-plan popular">
        <div>
            <h3>Basic</h3>
             <div class="price_old">₹1499<span>/month</span></div>
            <div class="price">₹999<span>/month</span></div>
            <div class="price_discount">Limited Period Offer</div>
            <p>Ideal for small businesses and startups.</p>
            <ul>
                <li>Up to 3 User Accounts</li>
                <li>Up to 10 Calculations per Day</li>
                <li>3D Visualization</li>
                <li>Full Client Management</li>
                <li>Priority Email Support</li>
                <li>Shareable Reports</li>
            </ul>
        </div>
        <button type="button" class="cta-button choose-plan-btn" data-plan-id="basic">Choose Basic</button>
    </div>

    <div class="pricing-plan">
        <div>
            <h3>Pro</h3>
            <div class="price_old">₹2999<span>/month</span></div>
            <div class="price">₹1999<span>/month</span></div>
            <div class="price_discount">Limited Period Offer</div>
            <p>For growing businesses needing more capacity.</p>
            <ul>
                <li>Up to 10 User Accounts</li>
                <li>Up to 50 Calculations per Day</li>
                <li>Advanced 3D Visualization</li>
                <li>Full Client Management & API Access</li>
                <li>Dedicated Phone & Email Support</li>
                <li>Custom Report Branding (Coming Soon)</li>
            </ul>
        </div>
        <button type="button" class="cta-button choose-plan-btn" data-plan-id="pro">Choose Pro</button>
    </div>

    <div class="pricing-plan">
        <div>
            <h3>Enterprise</h3>
            <div class="price">Contact Us</div>
            <p>Tailored solutions for large organizations.</p>
            <ul>
                <li>Unlimited User Accounts</li>
                <li>Unlimited Calculations</li>
                <li>Custom Visualizations & Integrations</li>
                <li>Advanced CRM Features</li>
                <li>Dedicated Account Manager</li>
                <li>On-Premise Option Available</li>
            </ul>
        </div>
        <a href="contact.php?subject=Enterprise+Plan+Inquiry" class="cta-button">Contact Sales</a>
    </div>
</div>

<div style="text-align: center; margin-top: 30px;">
    <p>All prices are in INR. Custom plans are available. Please <a href="contact.php">contact us</a> for more details.</p>
    <p>Payment processing powered by Razorpay.</p>
</div>

<!-- Razorpay Checkout Form Script -->
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const choosePlanButtons = document.querySelectorAll('.choose-plan-btn');

    choosePlanButtons.forEach(button => {
        button.addEventListener('click', function() {
            const planId = this.dataset.planId;
            // Check if user is logged in - simple check, server will validate too
            <?php if (!isset($_SESSION['user_uuid'])): ?>
                alert('Please login or register to choose a plan.');
                window.location.href = 'login.php?redirect_to=pricing.php'; // Redirect to login
                return;
            <?php endif; ?>

            // AJAX call to backend to create Razorpay order
            const formData = new FormData();
            formData.append('plan_id', planId);

            fetch('create_razorpay_order.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.order_id) {
                    const options = {
                        "key": data.key_id, // Your Razorpay Key ID
                        "amount": data.amount, // Amount in paisa
                        "currency": data.currency,
                        "name": "<?php echo APP_NAME; ?>", // Your business name
                        "description": data.description, // "Payment for " + data.plan_name
                        "order_id": data.order_id, // From your server
                        "callback_url": data.callback_url, // Server-side script to verify payment
                        "prefill": {
                            "name": data.prefill.name,
                            "email": data.prefill.email,
                            "contact": data.prefill.contact
                        },
                        "notes": data.notes,
                        "theme": {
                            "color": data.theme.color
                        },
                        "handler": function (response){
                            // This function is called after successful payment AND if callback_url is not provided or fails client-side.
                            // Razorpay strongly recommends using webhooks and callback_url for reliability.
                            // The primary verification should happen on your server via callback_url.
                            // You can redirect or show a message here, but server verification is key.
                            // For example:
                            // window.location.href = `payment_verification.php?razorpay_payment_id=${response.razorpay_payment_id}&razorpay_order_id=${response.razorpay_order_id}&razorpay_signature=${response.razorpay_signature}`;
                            // However, since callback_url is set, Razorpay will POST to it.
                            // This JS handler is more of a fallback or for actions immediately after the modal closes *before* server callback fully processes.
                            // Typically, you'd show a "Processing..." message and wait for server redirect from callback_url.
                             document.body.innerHTML += '<div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); color:white; display:flex; align-items:center; justify-content:center; z-index:9999;"><h2>Processing your payment... Please wait.</h2></div>';
                        }
                    };
                    const rzp1 = new Razorpay(options);
                    rzp1.on('payment.failed', function (response){
                        alert("Payment Failed: " + response.error.description + " (Code: " + response.error.code + ")");
                        // console.error("Payment Failed Details:", response.error);
                        // Log this error or redirect to a payment failed page
                        // Example: window.location.href = 'payment_failed.php?code='+response.error.code+'&desc='+response.error.description;
                    });
                    rzp1.open();
                } else {
                    alert('Error: Could not initiate payment. ' + (data.error || 'Unknown error.'));
                }
            })
            .catch(error => {
                console.error('Error creating Razorpay order:', error);
                alert('An error occurred while setting up the payment. Please try again.');
            });
        });
    });
});
</script>

<?php require_once 'templates/footer.php'; ?>
