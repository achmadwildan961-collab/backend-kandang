#pragma once
// GPIO4=D2 SDA, GPIO5=D1 SCL, historical LCD0x27/16x2; confirm electrically.
#ifndef FW_I2C_SDA
#define FW_I2C_SDA 4
#endif
#ifndef FW_I2C_SCL
#define FW_I2C_SCL 5
#endif
#ifndef FW_AHT20_ADDRESS
#define FW_AHT20_ADDRESS 0x38
#endif
#ifndef FW_BH1750_ADDRESS
#define FW_BH1750_ADDRESS 0x23
#endif
#ifndef FW_LCD_ENABLED
#define FW_LCD_ENABLED 0
#endif
#ifndef FW_LCD_ADDRESS
#define FW_LCD_ADDRESS 0x27
#endif
#ifndef FW_LCD_COLUMNS
#define FW_LCD_COLUMNS 16
#endif
#ifndef FW_LCD_ROWS
#define FW_LCD_ROWS 2
#endif
#ifndef FW_REGISTRY_DEVICE_ID
#define FW_REGISTRY_DEVICE_ID 0
#endif
#ifndef FW_DISPLAY_INDOOR_ID
#define FW_DISPLAY_INDOOR_ID 0
#endif
#include <PoultriqDefaults.h>
