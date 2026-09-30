#!/bin/sh
# Stand-in for LibreOffice in tests: writes <outdir>/<input basename>.pdf with a PDF magic header.
# Arguments mirror the real call: --headless --norestore -env:... --convert-to pdf --outdir <dir> <file>
outdir=""
while [ $# -gt 1 ]; do
  if [ "$1" = "--outdir" ]; then outdir="$2"; shift; fi
  shift
done
input="$1"
[ "${FAKE_SOFFICE_FAIL:-0}" = "1" ] && { echo "conversion failed" >&2; exit 1; }
base=$(basename "$input" .docx)
[ "${FAKE_SOFFICE_RECORD_CWD:-0}" = "1" ] && pwd > "$outdir/cwd.txt"
if [ "${FAKE_SOFFICE_WRITE_THEN_FAIL:-0}" = "1" ]; then printf '%%PDF-1.4 partial\n' > "$outdir/$base.pdf"; echo "crashed after writing" >&2; exit 1; fi
printf '%%PDF-1.4 fake\n' > "$outdir/$base.pdf"
