# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.11.0] – 2026-10-21

### Added

- Captures `gbraid` and `wbraid` alongside `gclid`. When the attributed click has no `gclid`, the conversion is uploaded with its `gbraid`, or else its `wbraid`, so clicks from iOS traffic and from browsers that strip `gclid` are reported too. Log lines and queue labels name the identifier type. (#5)
- The `kntnt_ad_attr_gads_multi_touch` filter decides how a conversion with several attributed clicks is uploaded. `highest` (the default) uploads it once, for the click with the highest weight and with the summed value. `split` uploads one conversion per click, each with its share of the value. (#3)

### Changed

- Requires Kntnt Ad Attribution 1.11.0 or later. With an older core plugin the add-on stays inactive, and an admin notice names the required and the installed version. The core's 1.10.0 update deletes click IDs stored by earlier versions, so conversions attributed to clicks made before that update are not uploaded. (#4, #5)
- The **Create Conversion Action** button creates an action that counts every conversion (`MANY_PER_CLICK`), which `gbraid` and `wbraid` uploads require. An existing action used for braid uploads must have **Count** set to **Every**. (#5)

### Fixed

- Conversions are uploaded with the `gclid` of the click they are attributed to, and the 6-hour upload delay counts from that click. Before, another visitor's `gclid` could be sent if they had clicked the same tracking URL later. (#4)
- Clicks with zero attribution weight are no longer uploaded. Google Ads counted each of them as a full conversion. (#3)
- `EXPIRED_EVENT` and the other rejections that no retry can fix are recognised by their error code and are no longer retried. Account, credential and conversion action errors stay retryable, so they are retried after the settings are saved. (#6)
