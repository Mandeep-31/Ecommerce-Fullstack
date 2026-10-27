import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import './style.css';

window.Pusher = Pusher;
const terminal = new Set(['COMPLETE', 'NOT_FOUND', 'CANCELED', 'FULL_REFUND', 'PARTIAL_REFUND']);
const money = (value) => `Rs. ${Number(value).toLocaleString('en-NP')}`;

function App() {
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState(() => JSON.parse(localStorage.getItem('little-things-cart') || '{}'));
  const [customer, setCustomer] = useState({ name: '', email: '', phone: '', address: '' });
  const [paymentProvider, setPaymentProvider] = useState('esewa');
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [order, setOrder] = useState(null);
  const [paymentStatus, setPaymentStatus] = useState('');
  const [gatewayHttpStatus, setGatewayHttpStatus] = useState(null);
  const orderId = new URLSearchParams(location.search).get('order');
  const paymentFailed = new URLSearchParams(location.search).get('payment') === 'failed';
  const cartItems = useMemo(() => products.filter((product) => cart[product.id]).map((product) => ({ ...product, quantity: cart[product.id] })), [products, cart]);
  const itemCount = cartItems.reduce((sum, item) => sum + item.quantity, 0);
  const total = cartItems.reduce((sum, item) => sum + Number(item.price) * item.quantity, 0);
  const filtered = products.filter((product) => `${product.name} ${product.description}`.toLowerCase().includes(query.toLowerCase()));

  useEffect(() => {
    fetch('/api/products', { headers: { Accept: 'application/json' } })
      .then((response) => { if (!response.ok) throw new Error('The shop is taking a short break. Please refresh in a moment.'); return response.json(); })
      .then(setProducts).catch((reason) => setError(reason.message));
  }, []);

  useEffect(() => { localStorage.setItem('little-things-cart', JSON.stringify(cart)); }, [cart]);

  useEffect(() => {
    if (!orderId) return undefined;
    let stopped = false;
    let timer;
    let echo;
    const update = (status) => {
      if (stopped) return;
      setPaymentStatus(status);
      if (terminal.has(status)) { stopped = true; clearTimeout(timer); }
    };
    try {
      echo = new Echo({
        broadcaster: 'reverb', key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || '127.0.0.1',
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8081),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 8081),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
      });
      echo.channel(`payments.${orderId}`).listen('.payment.updated', ({ status }) => update(status));
    } catch { /* The status endpoint below remains the fallback. */ }

    async function refreshOrder() {
      try {
        const [orderResult, statusResult] = await Promise.allSettled([
          fetch(`/api/orders/${orderId}`, { headers: { Accept: 'application/json' } }),
          fetch(`/api/orders/${orderId}/check-status`, { method: 'POST', headers: { Accept: 'application/json' } }),
        ]);
        if (orderResult.status === 'rejected') throw orderResult.reason;
        const orderResponse = orderResult.value;
        if (!orderResponse.ok) throw new Error('We could not find this order. Please check the link or contact the shop.');
        const details = await orderResponse.json();
        setOrder(details);
        setError('');
        const statusResponse = statusResult.status === 'fulfilled' ? statusResult.value : null;
        if (statusResponse?.ok) {
          const status = await statusResponse.json();
          setGatewayHttpStatus(status.gateway_http_status);
          update(status.order_status === 'COMPLETE' ? status.order_status : status.esewa_status === 'UNAVAILABLE' || status.esewa_status === 'INVALID_RESPONSE' ? status.esewa_status : status.order_status || status.esewa_status);
        } else {
          update('UNAVAILABLE');
        }
      } catch (reason) {
        if (!stopped) setError(reason.message || 'Could not reach the payment server. Retrying shortly.');
      }
      if (!stopped) timer = setTimeout(refreshOrder, 5000);
    }
    refreshOrder();
    return () => { stopped = true; clearTimeout(timer); echo?.leave(`payments.${orderId}`); echo?.disconnect(); };
  }, [orderId]);

  function changeQuantity(id, amount) {
    setCart((current) => {
      const next = { ...current };
      const quantity = (next[id] || 0) + amount;
      if (quantity < 1) delete next[id]; else next[id] = Math.min(quantity, 10);
      return next;
    });
  }

  async function checkout(event) {
    event.preventDefault();
    setBusy(true); setError('');
    try {
      const response = await fetch('/api/checkout', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ items: cartItems.map(({ id, quantity }) => ({ product_id: id, quantity })), customer, payment_provider: paymentProvider }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'Checkout could not start. Please check your details.');
      if (result.redirect_url) { location.assign(result.redirect_url); return; }
      const form = document.createElement('form');
      form.method = 'POST'; form.action = result.gateway_url;
      Object.entries(result.fields).forEach(([name, value]) => {
        const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
      });
      document.body.append(form); form.submit();
    } catch (reason) { setError(reason.message); setBusy(false); }
  }

  if (orderId) return <OrderPage order={order} status={paymentStatus} httpStatus={gatewayHttpStatus} failed={paymentFailed} error={error} />;

  return <main className="page">
    <header><a className="brand" href="#top">little things<span>®</span></a><nav><a href="#shop">Shop</a><a href="#story">Our story</a></nav><a className="bag" href="#checkout">BAG <b>{String(itemCount).padStart(2, '0')}</b></a></header>
    <section className="hero" id="top"><div className="hero-copy"><p className="eyebrow">THOUGHTFULLY CHOSEN · MADE FOR REAL LIFE</p><h1>Good things,<br /><i>for every day.</i></h1><p>Useful, lasting pieces for the everyday adventures—close to home or a little further out.</p><a className="hero-link" href="#shop">Explore the collection <span>↓</span></a></div><div className="hero-image"><img src="https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=1200&q=85" alt="Everyday backpack ready for an outing" /><span>KATHMANDU, NEPAL · EST. 2024</span></div></section>
    <section className="collection" id="shop"><div className="section-heading"><div><p className="eyebrow">THE LITTLE THINGS COLLECTION</p><h2>Good design. Daily use.</h2></div><label className="search">⌕ <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Find your next favourite" /></label></div>
      {error && <p className="alert">{error}</p>}
      {!products.length && !error && <p className="loading">Setting the shelves…</p>}
      <div className="product-grid">{filtered.map((product, index) => <article className="product-card" key={product.id}><div className="product-image"><img src={product.image_url} alt={product.name} loading="lazy" /><span>{['CUSTOMER FAVOURITE', 'MADE TO LAST', 'EVERYDAY ESSENTIAL', 'A LITTLE NOTE'][index % 4]}</span><button className="add-button" aria-label={`Add ${product.name} to bag`} onClick={() => setCart((current) => ({ ...current, [product.id]: Math.min((current[product.id] || 0) + 1, 10) }))}>+</button></div><div className="product-info"><div><h3>{product.name}</h3><p>{product.description}</p></div><strong>{money(product.price)}</strong></div></article>)}</div>
      {filtered.length === 0 && products.length > 0 && <p className="empty">No pieces match “{query}”. Try another search.</p>}
    </section>
    <section className="promise" id="story"><div><span>01</span><h3>Made to be used</h3><p>Well-considered essentials that earn their place in your day.</p></div><div><span>02</span><h3>Delivered with care</h3><p>We deliver across Nepal. Shipping details are confirmed at checkout.</p></div><div><span>03</span><h3>Here if you need us</h3><p>Questions about an order? We’re happy to help you find an answer.</p></div></section>
    <section className="checkout-section" id="checkout"><div className="checkout-title"><p className="eyebrow">YOUR LITTLE THINGS</p><h2>Bag & checkout</h2><p>Every order is prepared with care in Kathmandu.</p></div><div className="checkout-layout"><div className="bag-items">{cartItems.length ? cartItems.map((item) => <div className="bag-item" key={item.id}><img src={item.image_url} alt="" /><div className="bag-item-copy"><b>{item.name}</b><span>{money(item.price)}</span><div className="quantity"><button onClick={() => changeQuantity(item.id, -1)} aria-label="Remove one">−</button><span>{item.quantity}</span><button onClick={() => changeQuantity(item.id, 1)} aria-label="Add one">+</button></div></div><strong>{money(item.price * item.quantity)}</strong></div>) : <p className="empty-bag">Your bag is taking a little breather. <a href="#shop">Find something you love.</a></p>}
        <div className="total-row"><span>Subtotal · {itemCount} {itemCount === 1 ? 'item' : 'items'}</span><b>{money(total)}</b></div><p className="delivery-note">Delivery is included in the price shown. No hidden fees.</p></div>
        <form className="customer-form" onSubmit={checkout}><h3>Delivery details</h3><p>Where should we send your order?</p><label>Full name<input required maxLength="120" autoComplete="name" value={customer.name} onChange={(event) => setCustomer({ ...customer, name: event.target.value })} /></label><div className="form-row"><label>Email address<input required type="email" autoComplete="email" value={customer.email} onChange={(event) => setCustomer({ ...customer, email: event.target.value })} /></label><label>Phone number<input required type="tel" autoComplete="tel" value={customer.phone} onChange={(event) => setCustomer({ ...customer, phone: event.target.value })} /></label></div><label>Delivery address<textarea required rows="3" autoComplete="street-address" placeholder="Street, area, city" value={customer.address} onChange={(event) => setCustomer({ ...customer, address: event.target.value })} /></label><fieldset className="payment-options"><legend>Choose how to pay</legend><label className={`payment-option ${paymentProvider === 'esewa' ? 'selected' : ''}`}><input type="radio" name="payment_provider" value="esewa" checked={paymentProvider === 'esewa'} onChange={() => setPaymentProvider('esewa')} /><span className="provider-logo esewa-logo">e</span><span><b>eSewa</b><small>Pay securely with your eSewa wallet</small></span></label><label className={`payment-option ${paymentProvider === 'khalti' ? 'selected' : ''}`}><input type="radio" name="payment_provider" value="khalti" checked={paymentProvider === 'khalti'} onChange={() => setPaymentProvider('khalti')} /><span className="provider-logo khalti-logo">K</span><span><b>Khalti</b><small>Pay securely with your Khalti wallet</small></span></label></fieldset>{error && <p className="alert">{error}</p>}<button className={`pay-button ${paymentProvider === 'khalti' ? 'khalti-pay' : ''}`} disabled={busy || cartItems.length === 0}>{busy ? 'Preparing secure checkout…' : <>Continue to {paymentProvider === 'khalti' ? 'Khalti' : 'eSewa'} <span>{money(total)} ↗</span></>}</button><div className="secure"><span>♧</span> SECURE CHECKOUT <i /> POWERED BY <b>{paymentProvider === 'khalti' ? 'Khalti' : 'eSewa'}</b></div><p className="terms">You’ll continue to {paymentProvider === 'khalti' ? 'Khalti' : 'eSewa'} to complete payment. Your order is confirmed only after provider verification.</p></form></div></section>
    <footer><span>GOOD THINGS, EVERY DAY.</span><span>PAY WITH <b>eSewa · KHALTI</b></span><span>© LITTLE THINGS 2026 · KATHMANDU</span></footer>
  </main>;
}

function OrderPage({ order, status, httpStatus, failed, error }) {
  const providerName = order?.payment_provider === 'khalti' ? 'Khalti' : 'eSewa';
  const complete = status === 'COMPLETE' || order?.status === 'COMPLETE';
  const label = complete ? 'Payment confirmed' : status === 'CANCELED' || status === 'NOT_FOUND' ? 'Payment not completed' : failed ? 'Payment needs attention' : 'Confirming your payment';
  const message = complete ? `Your payment is verified with ${providerName}. Thank you for shopping with us.` : status === 'CANCELED' ? `${providerName} reports that this payment was cancelled or expired. You can return to the shop and try again.` : status === 'NOT_FOUND' ? `${providerName} has no completed payment for this order. Please try checkout again.` : failed ? 'The payment window returned without confirmation. We’re checking the official status before updating your order.' : status === 'UNAVAILABLE' ? `We could not reach ${providerName} right now. We’ll retry automatically; your order is not marked paid yet.` : status === 'INVALID_RESPONSE' ? `${providerName} returned a response we could not verify. We’ll check again; your order is not marked paid yet.` : `${providerName} is confirming this payment. This page refreshes automatically.`;
  return <main className="receipt-page"><header><a className="brand" href="/">little things<span>®</span></a><span className="receipt-secure">SECURE CHECKOUT · {providerName}</span></header><section className="receipt"><div className={`status-mark ${complete ? 'success' : ''}`}>{complete ? '✓' : '…'}</div><p className="eyebrow">{complete ? 'THANK YOU FOR YOUR ORDER' : 'ORDER UPDATE'}</p><h1>{label}</h1><p className="receipt-message">{message}</p>{error && <p className="alert">{error}</p>}{order && <><div className="order-number"><span>ORDER REFERENCE</span><b>{order.transaction_uuid}</b></div><div className="receipt-items">{order.items?.map((item, index) => <div className="receipt-item" key={index}><img src={item.product_image_url} alt="" /><span>{item.product_name} <small>× {item.quantity}</small></span><b>{money(item.line_total)}</b></div>)}</div><div className="total-row"><span>Order total</span><b>{money(order.total_amount)}</b></div></>}{status && <p className="status-code">{providerName} status · {status}{httpStatus ? ` · HTTP ${httpStatus}` : ''}</p>}<a className="back-link" href="/">← Back to the shop</a></section><footer><span>LITTLE THINGS · KATHMANDU</span><span>THANK YOU FOR SHOPPING SMALL</span></footer></main>;
}

createRoot(document.getElementById('root')).render(<App />);
