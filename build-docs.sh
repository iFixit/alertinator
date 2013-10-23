#!/bin/bash -e

cat > docs/Alertinator.rst <<EOT
Alertinator API Docs
====================
EOT
doxphp < Alertinator.php | doxphp2sphinx >> docs/Alertinator.rst
cd docs
source env/bin/activate
make html

