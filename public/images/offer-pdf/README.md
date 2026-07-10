# Offer PDF images

Drop-in artwork for the customer-offer PDF (`Generează Oferta PDF`). Any file you
place here is picked up automatically the next time an offer PDF is generated — no
code change needed. Accepted extensions, in match priority: **.jpg, .jpeg, .png, .webp**.

| What | Where to put it | Notes |
| --- | --- | --- |
| **Header banner** (fruit & veg photo) | `banner.jpg` in this folder | Falls back to the bundled `banner.png` illustration. A wide/landscape photo works best. |
| **Company logo** | `logo.png` in this folder | Only used when the tenant has no uploaded logo. A square image works best. |
| **Signature** | `signature.png` in this folder | Shown under "Întocmit de". Leave it out for a plain signature line. |
| **Product photos** | `products/<product>.jpg` | See below. |

## Product photos

For each product row, the PDF looks for an image in this order:

1. The product's own picture (uploaded via **Products → edit → Image**), or its
   category's picture — this is the recommended, reusable option.
2. `products/<product-name-slug>.jpg` in this folder, e.g. a product named
   `Cartofi dulci` → `products/cartofi-dulci.jpg`.
3. `products/<product-id>.jpg` in this folder, e.g. `products/22.jpg`.

If none exist, the row shows a neutral placeholder box.

Square photos (e.g. 400×400) look best and match the rest of the app's thumbnails.

> Bank accounts are **data**, not images: they are stored per tenant in the
> `offer_bank_accounts` tenant setting (a JSON array of `{ "bank", "iban" }`).
