// The v1 config extended stylelint-config-recommended-vue, which is not a
// declared dependency: stylelint therefore started with no rules at all and
// aborted. @nextcloud/stylelint-config is the package actually installed and
// already covers Vue single file components.
module.exports = {
	extends: '@nextcloud/stylelint-config',
}
