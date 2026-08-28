# Test icons

The host renders every icon through **symfony/ux-icons** (`lucide:*`) and vendors
its icon set with `bin/console ux:icons:import`. These tests are about the
module's markup, not about which glyph an icon resolves to, so this directory
holds just the names the templates ask for — two copied from the host's own set
and the rest as placeholders of the right shape.

Nothing in the test suite asserts on an icon. If one is missing the page still
renders (`ux_icons.ignore_not_found` is on in the test kernel); the files are
here only to keep the log clean, so a real warning is visible when it happens.
