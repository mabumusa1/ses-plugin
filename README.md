### Mautic AWS SES Plugin

This plugin enable Mautic 5 to run AWS SES as a transport, it also act as a sample on how to create plugins for Mautic 5

Visit [SteerCampaign.com](https://steercampaign.com) to find other plugins and integrations


### Installation

Clone repo.

```
git clone <repo-url> ScMailerSesBundle
```

Install Symfony SES bridge

```
composer require symfony/amazon-mailer
```

Install the plugin

```
rm -rf var/cache/dev/* var/cache/prod/*
php bin/console mautic:plugins:reload --env=prod
```
