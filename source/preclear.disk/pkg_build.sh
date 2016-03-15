#!/bin/bash
DIR="$(dirname "$(readlink -f ${BASH_SOURCE[0]})")"
tmpdir=/tmp/tmp.$(( $RANDOM * 19318203981230 + 40 ))
plugin=$(basename ${DIR})
archive="$(dirname $(dirname ${DIR}))/archive"
plg_file="$(dirname $(dirname ${DIR}))/plugins/${plugin}.plg"
version=$(date +"%Y.%m.%d")
package="${archive}/${plugin}-%s.txz"
md5="${archive}/${plugin}-%s.md5"

for x in "" a b c d e d f g h ; do
  package=$(printf "$package" "${version}${x}")
  md5=$(printf "$md5" "${version}${x}")
  if [[ ! -f $package ]]; then
    version="${version}${x}"
    break
  fi
done

sed -i -e "s#\(ENTITY\s*version[^\"]*\).*#\1\"${version}\"#" "$plg_file"

mkdir -p $tmpdir
cd "$DIR"
cp --parents -f $(find . -type f ! \( -iname "pkg_build.sh" -o -iname "sftp-config.json" -o -iname ".DS_Store"  \) ) $tmpdir/
cd "$tmpdir/"
makepkg -l y -c y "${package}"
cd "$archive/"
md5sum $(basename "$package") > "$md5"
rm -rf "$tmpdir"

# Verify and install plugin package
sum1=$(md5sum "${package}")
sum2=$(cat "$md5")
if [ "${sum1:0:32}" != "${sum2:0:32}" ]; then
  echo "Checksum mismatched.";
  rm "$md5" "${package}"
else
  echo "Checksum matched."
fi
