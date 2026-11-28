# Google & Bing Site Ownership Verification Guide

## 1. Recommended Property Type

Prioritize a **Domain Property** (`sc-domain:sysnoxis.com`) in Google Search Console so all protocols (`http`/`https`) and subdomains (`www` and apex) are unified under one verified property.

## 2. Supported Verification Methods

| Method                     | Configuration in Admin                                        | How SyncViet Handles It                                                                                        |
| -------------------------- | ------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| **DNS TXT (Recommended)**  | Select `DNS TXT`, paste `google-site-verification=...` token. | Live DNS verification via `dns_get_record($host, DNS_TXT)`. Never mutates your DNS provider.                   |
| **DNS CNAME**              | Select `DNS CNAME`, enter `host_label=target_host`.           | Live DNS verification via `dns_get_record($cnameHost, DNS_CNAME)`.                                             |
| **HTML Verification File** | Select `HTML File`, enter `googleXXXXXXXX.html`.              | Laravel dynamically serves `GET /google{token}.html` returning `google-site-verification: google{token}.html`. |
| **HTML Meta Tag**          | Select `HTML Meta Tag`, paste token or `<meta>` tag.          | Blade renders `<meta name="google-site-verification" content="...">` inside `<head>`.                          |
| **Bing Meta Tag**          | Enter `msvalidate.01` token.                                  | Blade renders `<meta name="msvalidate.01" content="...">` inside `<head>`.                                     |

## 3. Honest Verification Status Rule

Saving a verification token **NEVER** marks the domain as `verified`. SyncViet sets status to `pending_verification` until live DNS confirmation succeeds or verified property access is confirmed via Google Search Console API.
