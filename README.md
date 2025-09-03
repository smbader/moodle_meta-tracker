# PHP Jira & GitHub Issue Tracker

A web-based issue tracker and Kanban board that integrates with Jira and GitHub, allowing users to manage, view, and organize issues from both platforms. Built with PHP and MySQL, styled with Bulma CSS, and designed for easy deployment via Docker.

## Features

- **User Authentication**: Secure registration and login with email and password.
- **Dashboard**: Personalized dashboard displaying saved Jira issues with live status, summary, and update info.
- **Kanban Board**: Visualize and filter issues in a Kanban-style board, with filtering by tags and coworkers.
- **Jira Integration**: Connect your Jira account to fetch and display issue details in real time.
- **GitHub Issue Integration**: Add GitHub issues by link; fetches and displays issue details using your GitHub token.
- **Coworker Management**: Add, view, and remove coworkers, including their name, email, and GitHub handle.
- **User-Specific Settings**: Store and manage API credentials for Jira and GitHub securely per user.
- **Database Installation & Schema Sync**: Automated setup and schema synchronization using an XML schema file.
- **Session Management**: All main features require authentication for access.
- **Responsive UI**: Clean, modern interface using Bulma CSS and reusable templates.

## Getting Started

### Prerequisites
- Docker (recommended) or a local LAMP stack (PHP 7.4+, MySQL 5.7+)
- Composer (optional, if you wish to manage PHP dependencies)

### Installation
1. **Clone the repository**
   ```sh
   git clone <your-repo-url>
   cd docker-compose-lamp/www
   ```
2. **Configure Database**
   - Edit `config.php` if needed to match your MySQL settings (default: host `database`, user `docker`, pass `docker`, db `docker`).
3. **Start Services (Docker)**
   - Use your Docker Compose setup to start MySQL and PHP/Apache containers.
4. **Install Database Schema**
   - Visit `/install.php` in your browser to initialize and sync the database schema from `tables.xml`.

### Usage
1. **Register a New User**
   - Go to `/register.php` and create an account.
2. **Login**
   - Access `/login.php` and sign in.
3. **Set Up Credentials**
   - Enter your Jira and GitHub credentials in the appropriate settings page (see `credentials.php`).
4. **Add Issues**
   - Add Jira issues by key or GitHub issues by link.
5. **View Dashboard & Kanban**
   - Use the dashboard and Kanban board to manage and filter your issues.
6. **Manage Coworkers**
   - Add or remove coworkers for collaboration and filtering.

### Configuration
- **Jira**: Requires your Jira domain, email, and API token (stored per user).
- **GitHub**: Requires your GitHub username and a personal access token (stored per user).
- **Database**: MySQL connection info is set in `config.php`.

### File Structure
- `index.php` — Main dashboard
- `kanban.php` — Kanban board view
- `add_github_issue.php` — Add GitHub issues
- `coworkers.php` — Manage coworkers
- `credentials.php` — Set API credentials
- `install.php` — Database schema setup
- `login.php`, `register.php`, `logout.php` — Authentication
- `assets/` — CSS and images
- `template/` — Header and footer templates
- `tables.xml` — Database schema definition

### Database
- The schema is defined in `tables.xml` and installed via `install.php`.
- User, issue, config, and coworker data are stored in MySQL.

### Notes
- **Security**: API tokens are stored per user. Never share your credentials.
- **API Limits**: Jira and GitHub API usage is subject to rate limits.
- **Customization**: You can extend the schema or add new integrations by editing the PHP files and `tables.xml`.

## License
MIT License. See LICENSE file for details.

## Credits
- [Bulma CSS](https://bulma.io/)
- [Jira REST API](https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/)
- [GitHub REST API](https://docs.github.com/en/rest)

---
For questions or contributions, please open an issue or pull request.

