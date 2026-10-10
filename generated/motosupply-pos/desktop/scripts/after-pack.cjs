// Sets the Windows icon and version information inside MotoSupply POS.exe after packaging.
// (electron-builder normally uses rcedit through Wine for this; resedit does it in pure JavaScript.)
const fs = require('node:fs');
const path = require('node:path');

exports.default = async function afterPack(context) {
  if (context.electronPlatformName !== 'win32') return;
  const ResEdit = await import('resedit');
  const { NtExecutable, NtExecutableResource, Resource, Data } = ResEdit.default ?? ResEdit;
  const exe = path.join(context.appOutDir, `${context.packager.appInfo.productFilename}.exe`);
  const version = context.packager.appInfo.version;
  const res = NtExecutableResource.from(NtExecutable.from(fs.readFileSync(exe)));
  const ico = Data.IconFile.from(fs.readFileSync(path.join(__dirname, '..', 'build', 'icon.ico')));
  Resource.IconGroupEntry.replaceIconsForResource(res.entries, 1, 1033, ico.icons.map((i) => i.data));
  const vi = Resource.VersionInfo.fromEntries(res.entries)[0] ?? Resource.VersionInfo.createEmpty();
  const [a, b, c] = version.split('.').map(Number);
  vi.setFileVersion(a, b, c, 0, 1033);
  vi.setProductVersion(a, b, c, 0, 1033);
  vi.setStringValues({ lang: 1033, codepage: 1200 }, {
    FileDescription: 'MotoSupply POS (cashier)', ProductName: 'MotoSupply POS', CompanyName: 'MotoSupply',
    LegalCopyright: 'Copyright © 2026 MotoSupply', OriginalFilename: 'MotoSupply POS.exe', InternalName: 'MotoSupply POS',
    FileVersion: version, ProductVersion: version,
  });
  vi.outputToResourceEntries(res.entries);
  const out = NtExecutable.from(fs.readFileSync(exe));
  res.outputResource(out);
  fs.writeFileSync(exe, Buffer.from(out.generate()));
  console.log(`  • set icon and version ${version} in ${path.basename(exe)}`);
};
