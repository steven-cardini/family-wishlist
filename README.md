# Family Wishlist

A small, self-hosted web app for sharing family wishlists. Relatives browse wishes and reserve a gift without an account, so nobody buys the same thing twice.

## How it works

- **Parents** maintain recipients, categories and wishes in a password-protected admin area.
- **Relatives** open a private link, filter wishes by person, category or availability, and reserve a gift with just their name and email.
- **Reservations** trigger a confirmation email with a cancellation link. Parents are notified of reservations and cancellations, except for wishes that are their own, so surprises stay intact.

## Built with

PHP, SQLite, Pico.css and Alpine.js. There is no build step. It runs on any basic PHP web hosting.

## Documentation

- [docs/project_documentation.md](docs/project_documentation.md): features, tech stack, project structure
- [docs/technical_specification.md](docs/technical_specification.md): requirements, security and business rules
- [AGENTS.md](AGENTS.md): setup commands and contribution rules

## License

See [LICENSE](LICENSE).
