# Alfa Commerce

A full-featured eCommerce component for Joomla 4.x / 5.x, developed by [Easylogic](https://easylogic.gr).

> **Status:** Pre-alpha — the core is being actively built and tested.

## Features

- **Product Catalog** — Items, categories, manufacturers, custom fields
- **Cart & Checkout** — Full shopping cart with multi-step checkout
- **Orders Management** — Order lifecycle, statuses, history tracking
- **Payments** — Pluggable payment gateways (Standard, Revolut, Viva Wallet)
- **Shipping** — Pluggable shipment methods (Standard, BoxNow)
- **Pricing Engine** — Taxes, discounts, coupons, multi-currency support
- **Users & Groups** — Customer management with user group pricing
- **REST API** — 18 JSON-API endpoints for third-party integrations
- **SEO** — SEF URLs, metadata management
- **Multilingual** — Full i18n support (en-GB, el-GR)

## Requirements

- PHP 8.2+
- Joomla 4.x or 5.x
- MySQL (utf8mb4)

## Installation

1. Download the latest release ZIP from the [main branch](https://github.com/Easylogic-CO-LP/Alfa-Commerce/archive/refs/heads/main.zip)
2. In your Joomla admin, go to **System > Install > Extensions**
3. Upload the ZIP file
4. The installer will automatically set up the component, plugins, and modules

## Project Structure

```
administrator/          Backend admin panel (MVC, forms, services, events)
site/                   Frontend customer-facing views and logic
api/                    REST JSON-API controllers (18 endpoints)
plugins/
  alfa-payments/        Payment gateways (standard, revolut, viva)
  alfa-shipments/       Shipping methods (standard, boxnow)
  alfa-fields/          Custom form field types (text, textarea)
  webservices/          API routing plugin
modules/
  mod_alfa_cart/        Shopping cart module
  mod_alfa_search/      Product search module
media/com_alfa/         CSS, JavaScript, images
```

## API

Alfa Commerce exposes a REST API for integration with external applications.

[![Run in Postman](https://run.pstmn.io/button.svg)]([https://null.postman.co/collection/40562641-db6c701d-6cee-4955-96b3-d357447b9bfe?source=rip_markdown](https://app.getpostman.com/run-collection/40562641-33ef70bd-e1ce-4aba-8119-c055ab328589?action=collection%2Ffork&source=rip_markdown&collection-url=entityId%3D40562641-33ef70bd-e1ce-4aba-8119-c055ab328589%26entityType%3Dcollection%26workspaceId%3Db23f2240-19a2-4390-8194-519ced76ff26))

## Documentation

Full documentation is available at **[manual.alfacommerce.gr](https://manual.alfacommerce.gr)**

## Contributing

We welcome contributions from everyone. See [CONTRIBUTING.md](CONTRIBUTING.md) for the full guide.

**Quick start:**

```bash
# Fork and clone
git clone https://github.com/YOUR-USERNAME/Alfa-Commerce.git
cd Alfa-Commerce

# Create a feature branch from developer
git checkout developer
git checkout -b feature/my-feature

# Make changes, commit, push
git push origin feature/my-feature

# Open a Pull Request targeting the "developer" branch
```

### Branch Strategy

| Branch | Purpose |
|--------|---------|
| `main` | Stable releases. Never commit directly. |
| `developer` | Active development. Base your branches here. |
| `feature/*` | New features |
| `fix/*` | Bug fixes |

## Contact

- **Website:** [easylogic.gr](https://easylogic.gr)
- **Email:** info@easylogic.gr
- **Issues:** [GitHub Issues](https://github.com/Easylogic-CO-LP/Alfa-Commerce/issues)

## License

Developed by the Easylogic Team.
