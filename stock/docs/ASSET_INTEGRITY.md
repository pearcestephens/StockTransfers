# Asset Integrity – Pack System Shim

This document tracks Subresource Integrity (SRI) hashes for externally deliverable JS assets so templates can lock versions.

## Files

### pack-unified.min.js (v2.0.0-shim)
Integrity (SHA384): (compute in deployment pipeline with: `openssl dgst -sha384 -binary pack-unified.min.js | openssl base64 -A`)

Placeholder (must compute): `sha384-REPLACE_ME_AFTER_PIPELINE_COMPUTES`

## Workflow
1. After any change to `pack-unified.min.js`, recompute SRI.
2. Update template tag example:

```
<script src="/modules/transfers/stock/assets/js/pack-unified.min.js" integrity="sha384-..." crossorigin="anonymous"></script>
```

3. Perform a hard refresh (Ctrl+Shift+R) to validate the integrity check passes.

## Notes
- Do not rely on this hash for internal module loading order — only tamper detection.
- Keep minified file below 25KB (current: <5KB) per org performance standards.
