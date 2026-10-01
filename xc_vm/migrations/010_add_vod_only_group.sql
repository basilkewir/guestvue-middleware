-- "VOD Only" panel-user group, the vod admin role created in the panel UI on
-- kotel-hms-server-kribi. An admin account limited to VOD management, that is
-- movies, series, episodes, the VOD mass-edit tools and TV profiles. No access
-- to streams, servers, users, settings, billing or the rest of the panel.
--
-- Fresh installs get this row from bin/install/database.sql and this migration
-- brings it to installs that predate the role. Idempotent, skipped when a group
-- with this name already exists. Keep semicolons out of these comments, the
-- runner splits statements on them.
INSERT INTO `users_groups`
	(`group_name`, `is_admin`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`,
	 `delete_users`, `allowed_pages`, `can_delete`, `create_sub_resellers`, `create_sub_resellers_price`,
	 `reseller_client_connection_logs`, `can_view_vod`, `allow_download`, `minimum_trial_credits`,
	 `allow_restrictions`, `allow_change_username`, `allow_change_password`, `minimum_username_length`,
	 `minimum_password_length`, `allow_change_bouquets`, `notice_html`, `subresellers`)
SELECT 'VOD Only', 1, 0, 0, 'day',
	   0, '["movies","add_movie","edit_movie","mass_sedits_vod","series","add_series","edit_series","episodes","add_episode","edit_episode","mass_sedits","tprofile"]', 1, 0, 0,
	   0, 1, 1, 0,
	   0, 1, 1, 8,
	   8, 0, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM `users_groups` WHERE `group_name` = 'VOD Only')
