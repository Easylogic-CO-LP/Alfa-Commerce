# alfa-commerce (Pre-alpha Version)

🚀 About alfa-commerce

Welcome to alfa-commerce, the smartest, fastest, and easiest eCommerce solution for Joomla developed by Easylogic.
This project is currently in its Pre-alpha phase, with rapid developments underway to provide an optimized eCommerce experience.

While we are still in the pre-alpha phase, the core is being actively built and tested.

🛠️ Installation (Pre-alpha Testing)

    Go to main branch
    Download the whole zip
    Install it in joomla.

🌐 API Integration with Postman

The Alfa-Commerce API empowers developers to seamlessly integrate eCommerce functionalities into third-party applications. Use the button below to explore the API directly in Postman:

[<img src="https://run.pstmn.io/button.svg" alt="Run In Postman" style="width: 128px; height: 32px;">](https://null.postman.co/collection/40562641-db6c701d-6cee-4955-96b3-d357447b9bfe?source=rip_markdown)


🤝 Contributing

Contributions are what make the open-source community such an amazing place to learn, inspire, and create. We welcome contributions from everyone, especially those excited to help alfa-commerce grow.

### Step-by-Step Contribution Guide

1. **Fork** the repository on GitHub and **clone** your fork:

   ```bash
   git clone https://github.com/<your-username>/Alfa-Commerce.git
   cd Alfa-Commerce
   ```

2. **Install** the project dependencies (this sets up PHPStan and PHP-CS-Fixer):

   ```bash
   composer install
   ```

3. **Create** a feature branch based on the developer branch (`work`):

   ```bash
   git checkout -b my-feature-branch origin/work
   ```

4. **Run** the static analysis and coding style checks:

   ```bash
   composer phpstan
   composer phpcsfixer
   ```

5. **Make** your changes, **commit**, and **push** the branch:

   ```bash
   git add .
   git commit -m "Describe your change"
   git push origin my-feature-branch
   ```

6. **Open a Pull Request**. The CI workflow will automatically run the same PHPStan and PHP-CS-Fixer checks. Address any issues reported by the CI before requesting a review.

You can also get in touch with the team if you want to be a part of the project.

## Development Tools

This project uses Composer to manage development utilities such as
[PHPStan](https://phpstan.org/) and
[PHP-CS-Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer).

1. Install the dependencies:

   ```bash
   composer install
   ```

2. Run a static analysis:

   ```bash
   composer phpstan
   ```

3. Check the coding style:

   ```bash
   composer phpcsfixer
   ```

## Continuous Integration

GitHub Actions run the same PHPStan and PHP-CS-Fixer checks on each push and pull request. The workflow is defined in `.github/workflows/ci.yml` and helps keep the codebase consistent. Make sure the local checks pass before opening a pull request so the CI succeeds.


Developer Contact

    Easylogic Website
    Contact the dev team at info@easylogic.gr

Documentation for alfa-commerce is coming soon. For now, you can refer to the codebase and the inline comments to understand the structure.
🏷️ License

Developed with ❤️ by the Easylogic Team.
