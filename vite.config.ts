import { defineCraftConfig } from '@hardimpactdev/craft-ui/vite';
import os from 'os';

export default defineCraftConfig({
    https: {
        cert: `${os.homedir()}/.config/orbit/ssl/vite.crt`,
        key: `${os.homedir()}/.config/orbit/ssl/vite.key`,
    },
});
