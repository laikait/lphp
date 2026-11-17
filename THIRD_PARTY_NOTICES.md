# Third-party notices

The framework's own code is MIT-licensed: see [LICENSE](LICENSE), which is also what
`composer.json` declares. This file lists what it ships that is **not** its own, and
under which license. Composer dependencies under `vendor/` carry their own licenses
and are not repeated here.

## Swagger UI

| | |
|---|---|
| Where | `engine/OpenApi/ui/` (`swagger-ui-bundle.js`, `swagger-ui.css`) |
| Version | 5.33.1 |
| Copyright | 2020-2021 SmartBear Software Inc. |
| License | Apache License 2.0 |
| Files kept with it | `engine/OpenApi/ui/LICENSE` (the license text), `engine/OpenApi/ui/NOTICE` |

It serves the API documentation page (`openapi.docs`), from the framework itself, so
the page works offline and calls no third party. The Apache-2.0 terms apply to those
files only. They do not change the license of the rest of the framework, and the
license allows redistribution inside an MIT-licensed project as long as the license
text and the NOTICE stay with the files, which is why they sit beside them.

## Code inside `swagger-ui-bundle.js`

The bundle contains its own dependencies. Their licenses and copyright notices are in
`engine/OpenApi/ui/swagger-ui-bundle.js.LICENSE.txt`, which must stay with it. At the
time of writing they are MIT (React, safe-buffer and others), BSD-3-Clause (ieee754)
and DOMPurify, which is offered under Apache-2.0 or MPL-2.0.

## If you redistribute the framework

Keep `LICENSE`, this file and everything in `engine/OpenApi/ui/` together. Removing
`openapi.docs` support and that folder removes the Apache-2.0 material with it.
