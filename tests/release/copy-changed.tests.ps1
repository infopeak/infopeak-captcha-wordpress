# Tests the file comparison in tools/release.ps1 without running the release.
#   powershell -NoProfile -ExecutionPolicy Bypass -File tests\release\copy-changed.tests.ps1

$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot '..\..\tools\release.ps1'
$ast = [System.Management.Automation.Language.Parser]::ParseFile((Resolve-Path $script), [ref]$null, [ref]$null)

# Load $textExtensions and the comparison functions exactly as they are in the script.
$assign = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and $n.Left.Extent.Text -eq '$textExtensions' }, $true)
. ([scriptblock]::Create($assign.Extent.Text))
foreach ($name in 'ConvertTo-Lf', 'Test-SameContent', 'Copy-Changed') {
    $fn = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true)
    . ([scriptblock]::Create($fn.Extent.Text))
}

$dir = Join-Path ([System.IO.Path]::GetTempPath()) ('ipkcap-copy-test-' + [guid]::NewGuid())
New-Item -ItemType Directory $dir | Out-Null
function Write-Bytes($name, [byte[]]$bytes) { $p = Join-Path $dir $name; [System.IO.File]::WriteAllBytes($p, $bytes); $p }

$failed = 0
function Check($cond, $what) {
    if ($cond) { Write-Host "PASS $what" } else { Write-Host "FAIL $what" -ForegroundColor Red; $script:failed++ }
}

$lf   = [byte[]](0x61, 0x0A, 0x62, 0x0A)
$crlf = [byte[]](0x61, 0x0D, 0x0A, 0x62, 0x0D, 0x0A)
Check (Test-SameContent (Write-Bytes 'a.php' $lf) (Write-Bytes 'b.php' $crlf)) 'text: CRLF and LF count as the same'
Check (-not (Test-SameContent (Write-Bytes 'c.txt' $lf) (Write-Bytes 'd.txt' ([byte[]](0x61, 0x0A, 0x63, 0x0A))))) 'text: different content is different'
Check (-not (Test-SameContent (Write-Bytes 'e.txt' ([byte[]](0x61, 0x0D, 0x62))) (Write-Bytes 'f.txt' ([byte[]](0x61, 0x62))))) 'text: a lone CR is content, not a line ending'

# A .mo file differing only in a 0x0D byte (as a length or offset can) must count as changed.
$mo1 = [byte[]](0xDE, 0x12, 0x04, 0x95, 0x0D, 0x0A, 0x00, 0x01)
$mo2 = [byte[]](0xDE, 0x12, 0x04, 0x95, 0x0A, 0x00, 0x01)
$mo3 = [byte[]](0xDE, 0x12, 0x04, 0x95, 0x0D, 0x00, 0x01)
$mo4 = [byte[]](0xDE, 0x12, 0x04, 0x95, 0x00, 0x01)
Check (-not (Test-SameContent (Write-Bytes 'g.mo' $mo1) (Write-Bytes 'h.mo' $mo2))) 'binary: CR LF against LF is different'
Check (-not (Test-SameContent (Write-Bytes 'i.mo' $mo3) (Write-Bytes 'j.mo' $mo4))) 'binary: an extra 0x0D is different'
Check (Test-SameContent (Write-Bytes 'k.mo' $mo1) (Write-Bytes 'l.mo' $mo1)) 'binary: identical bytes are the same'

# Copy-Changed copies a changed .mo and leaves a text file that only differs in line endings.
$target = Write-Bytes 'target.mo' $mo4
Copy-Changed (Write-Bytes 'source.mo' $mo3) $target 'target.mo' | Out-Null
Check ([System.Linq.Enumerable]::SequenceEqual([System.IO.File]::ReadAllBytes($target), $mo3)) 'Copy-Changed: changed .mo is copied'
$textTarget = Write-Bytes 'keep.php' $crlf
Copy-Changed (Write-Bytes 'src.php' $lf) $textTarget 'keep.php' | Out-Null
Check ([System.Linq.Enumerable]::SequenceEqual([System.IO.File]::ReadAllBytes($textTarget), $crlf)) 'Copy-Changed: text with only line-ending changes is left alone'

Remove-Item -Recurse -Force $dir
if ($failed) { Write-Host "$failed failed" -ForegroundColor Red; exit 1 }
Write-Host 'All passed'
