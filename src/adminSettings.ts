import axios from '@nextcloud/axios'
import { createApp } from 'vue'
import AdminSettings from './views/AdminSettings.vue'

// Required for Nextcloud OCS API calls
axios.defaults.headers.common['OCS-APIRequest'] = 'true'

const app = createApp(AdminSettings)
app.mount('#nccare-admin')
