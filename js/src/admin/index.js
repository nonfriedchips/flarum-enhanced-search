import app from 'flarum/admin/app';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';

app.initializers.add('nonfriedchips-enhanced-search', () => {
  if (isExtensionEnabled('clarkwinkelmann-scout')) {
    app.alerts.show(
      { type: 'error', dismissible: true },
      app.translator.trans('nonfriedchips-enhanced-search.admin.scout_conflict')
    );
  }

  const indexState = app.data.settings['nonfriedchips-enhanced-search.index_dirty'];

  if (indexState && indexState !== '0' && indexState !== 'false') {
    app.alerts.show(
      { type: 'warning', dismissible: true },
      app.translator.trans('nonfriedchips-enhanced-search.admin.index_dirty')
    );
  }

  app.extensionData
    .for('nonfriedchips-enhanced-search')
    .registerSetting({
      setting: 'nonfriedchips-enhanced-search.typo_tolerance',
      label: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.typo_tolerance_label'),
      help: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.typo_tolerance_help'),
      type: 'boolean',
    })
    .registerSetting({
      setting: 'nonfriedchips-enhanced-search.search_post_content',
      label: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.search_post_content_label'),
      help: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.search_post_content_help'),
      type: 'boolean',
    })
    .registerSetting({
      setting: 'nonfriedchips-enhanced-search.one_typo_length',
      label: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.one_typo_length_label'),
      help: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.one_typo_length_help'),
      type: 'number',
      min: 3,
      max: 32,
    })
    .registerSetting({
      setting: 'nonfriedchips-enhanced-search.two_typo_length',
      label: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.two_typo_length_label'),
      help: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.two_typo_length_help'),
      type: 'number',
      min: 5,
      max: 64,
    })
    .registerSetting({
      setting: 'nonfriedchips-enhanced-search.native_result_threshold',
      label: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.native_result_threshold_label'),
      help: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.native_result_threshold_help'),
      type: 'number',
      min: 1,
      max: 100,
    })
    .registerSetting({
      setting: 'nonfriedchips-enhanced-search.candidate_limit',
      label: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.candidate_limit_label'),
      help: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.candidate_limit_help'),
      type: 'number',
      min: 20,
      max: 300,
    })
    .registerSetting({
      setting: 'nonfriedchips-enhanced-search.suggestion_min_length',
      label: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.suggestion_min_length_label'),
      help: app.translator.trans('nonfriedchips-enhanced-search.admin.settings.suggestion_min_length_help'),
      type: 'number',
      min: 2,
      max: 10,
    });
});
