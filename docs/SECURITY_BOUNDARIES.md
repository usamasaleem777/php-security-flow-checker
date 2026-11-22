# Security boundaries

Use this repository for authorized security assessment. The model engine accepts local finite-state JSON models. The corrected control suite accepts no target or mapping configuration and performs its checks within the PHP CLI process.

The fixture uses synthetic records and test session state. Native PHP session files live in a directory created for the run with a random name. The suite closes each session after use, removes its session files, and checks that the directory was removed. The reports record operation results and effect counts.

The suite exercises authorization and token handling at the PHP method boundary. Its results provide no coverage of HTTP routing, browser cookies, redirects, network timeouts, deployment settings, or production data stores. The finite-state analysis and the corrected control tests each establish claims within their respective scope.
