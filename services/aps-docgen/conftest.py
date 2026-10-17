"""Put the service root on sys.path so tests can import context/render.

The service is a flat set of modules rather than an installed package, so
`tests/` needs the parent directory importable.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
