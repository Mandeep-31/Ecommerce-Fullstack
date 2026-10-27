# eSewa shop starter

The app is split into two folders so the UI and payment code are easy to find:

- `frontend/` is a small React + Vite product page.
- `backend/` is Laravel + SQLite. It owns products, orders, signatures, payment verification, and WebSocket updates.

See [ENVIRONMENT.md](ENVIRONMENT.md) for a field-by-field explanation of the backend and frontend `.env` files, including session duration.

## Run it locally

Use three terminals from the project root:

```sh
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8001
```

```sh
cd frontend
npm install
cp .env.example .env
npm run dev -- --host 127.0.0.1 --port 5174
```

```sh
cd backend
php artisan reverb:start --host=127.0.0.1 --port=8081
```

Open `http://127.0.0.1:5174`. The backend `.env` contains eSewa UAT settings. Its UAT secret is `8gBm/:&EnhH.1/q`; omit the trailing `(` shown in some eSewa sample text, which causes `ES104 Invalid payload signature`. For a merchant account or production, replace these values with the credentials and URLs eSewa provides. Never put `ESEWA_SECRET_KEY` in the frontend.

## How checkout works

1. React loads product details from `GET /api/products`.
2. On checkout, React sends only the product ID and quantity. Laravel reads the price from SQLite, creates a pending order, signs the eSewa form fields with the secret in `backend/.env`, and returns the form data.
3. The browser posts that form to eSewa and the customer completes payment there.
4. eSewa returns a signed response to Laravel. Laravel checks the signature and order details, then asks eSewa's status endpoint whether the transaction is complete.
5. Laravel saves the verified result and broadcasts it through Reverb. The React page listens on that order's channel and shows the result. The WebSocket reports payment status after eSewa accepts checkout; it cannot replace eSewa's required form signature.

Products for the demo are inserted by `php artisan db:seed`; edit `backend/database/seeders/DatabaseSeeder.php` to change the sample product. Payment status is only marked complete after the server-side eSewa status check succeeds.
