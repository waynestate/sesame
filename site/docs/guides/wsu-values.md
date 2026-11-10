---
title: "WSU values (Playbook) | SESAME docs"
description: "Four settings carry Wayne State's shared values: where the CAS server lives, and who signs the gateway and oauth2 assertions."
url: https://sesame.apps.wayne.edu/docs/guides/wsu-values.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# WSU values (Playbook) [#](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html#wsu-values-playbook)

These four settings carry Wayne State's shared values. Set them in the environment, or let the private Playbook package supply them when the environment is empty, and a fresh app boots against the real services.

Open sesame, minus the incantation. Four settings carry Wayne State's shared values: where the CAS server lives, and who signs the gateway and oauth2 assertions.

| Setting | Config key | Meaning |
| --- | --- | --- |
| `SESAME_CAS_BASE_URL` | `cas.base_url` | The CAS server, an absolute https:// URL. |
| `SESAME_GATEWAY_ISSUER` | `gateway.issuer` | The `iss` claim the gateway assertion must carry. |
| `SESAME_OAUTH2_ISSUER` | `oauth2.issuer` | The `iss` claim the bearer token must carry. |
| `SESAME_OAUTH2_JWKS_URL` | `oauth2.jwks_url` | The URL of the issuer's key set. |

Set them in the environment, as with every other setting. When the environment is empty, the private [waynestate/playbook](https://git.wayne.edu/enterprise-apps/playbook) package supplies the university's values, so a fresh app boots against the real services without a `.env` full of addresses. The environment always wins: a set variable beats the playbook, and an empty one counts as unset.

The playbook is never required. It sits in `suggest`, not `require`, and SESAME runs fine without it, reading these four from the environment alone.

## Get the Playbook [#](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html#get-the-playbook)

Get it through Composer, fetch the values into a `.env.playbook` file, or clone the repository.

### Composer [#](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html#composer)

Add the repository to your `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://git.wayne.edu/enterprise-apps/playbook.git" }
    ]
}
```

then require it:

```bash
composer require waynestate/playbook
```

Composer keeps the git.wayne.edu credentials it asks for in `auth.json` as an `http-basic` entry, so later installs do not ask again.

### Fetch the values [#](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html#fetch-the-values)

**curl (Linux, macOS).**

```bash
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -o .env.playbook
```

**PowerShell (Windows).**

```powershell
Invoke-WebRequest -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -OutFile .env.playbook
```

### A clone [#](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html#a-clone)

When the app keeps its private dependencies by hand:

```bash
git clone https://git.wayne.edu/enterprise-apps/playbook.git
```

## Use the values [#](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html#use-the-values)

A fetched `.env.playbook` is plain `KEY=value` lines: copy the ones you need into your app's `.env` (or your Citadel environment). SESAME does not read `.env.playbook` itself.

The token is your own: `read_api` for curl, `read_repository` for clone and Composer.

Playbook is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access.

Raw-PHP apps that build their config by hand can resolve a value the same way the Laravel config does:

```php
use Waynestate\Sesame\Support\Setting;

$baseUrl = Setting::get('SESAME_CAS_BASE_URL'); // env, then playbook, else null
```

## Related [#](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html#related)

- [Which driver for my app?](https://sesame.apps.wayne.edu/docs/guides/which-driver.html)
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)
