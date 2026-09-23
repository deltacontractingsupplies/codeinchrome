//go:build linux

package sites

import "testing"

// The hosts run the agent under systemd's RestrictSUIDSGID=, which answers
// openat2 with ENOSYS. Every symlink-swap guarantee must hold on the openat
// walk as well, so the tests that prove them run again with openat2 off.
func TestEveryGuaranteeHoldsWithoutOpenat2(t *testing.T) {
	noOpenat2.Store(true)
	t.Cleanup(func() { noOpenat2.Store(false) })
	for name, f := range map[string]func(*testing.T){
		"swapped folder":    TestWritesReadsAndMovesRefuseAFolderSwappedForASymlink,
		"tree walk":         TestTheTreeWalkNeverDescendsThroughASwappedFolder,
		"planted symlink":   TestUnzipAndCopyNeverWriteThroughAPlantedSymlink,
		"in-site links":     TestAnInSiteSymlinkStillReadsAndWrites,
		"copy and listing":  TestCopyAndListingStillWorkOnAnOrdinaryTreeAndThroughInSiteLinks,
		"mkdir/rename/copy": TestMkdirRenameCopyAndMoveStayInsideTheSite,
		"no walk follows":   TestNoWalkFollowsASymlinkOutOfTheSite,
		"zip and unzip":     TestZipAndUnzipRefuseToEscapeTheSite,
		"upload/download":   TestUploadAndDownloadBinaryFilesByteForByte,
		"never replaces":    TestAMoveNeverReplacesWhatIsAtTheDestination,
		"zip archive":       TestZipStillProducesAnArchiveOfTheFolder,
	} {
		t.Run(name, f)
	}
	if !noOpenat2.Load() {
		t.Fatal("the walk was not the one exercised")
	}
}
