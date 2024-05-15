const { Readable, Writable, PassThrough } = require('stream');

function createChannel() {
    const passThrough = new PassThrough();
    const writable = new Writable({
        objectMode: true,
        write(chunk, encoding, callback) {
            // Optionally process data
            //console.log('Data written: ', chunk.toString());
            callback();
        }
    });
    passThrough.pipe(writable);
    return { readable: passThrough, writable: passThrough };
}

async function writer(writeStream, count, message) {
    return new Promise((resolve, reject) => {
        for (let i = 0; i < count; i++) {
            if (!writeStream.write(message)) {
                writeStream.once('drain', () => {
                    writeStream.write(message);
                });
            }
        }
        writeStream.end();
        writeStream.on('finish', () => {
            //console.log('Write finished');
            resolve();
        });
        writeStream.on('error', reject);
    });
}

async function main() {
    const startTime = process.hrtime.bigint();

    for (let i = 0; i < 10000; i++) {
        const { readable: read1, writable: write1 } = createChannel();
        const { readable: read2, writable: write2 } = createChannel();

        const readProcess = new Promise((resolve, reject) => {
            read1.on('data', (chunk) => {
                //console.log('Reading:', chunk.toString());
                if (!write2.write(chunk)) {
                    write2.once('drain', () => write2.write(chunk));
                }
            });
            read1.on('end', () => {
                write2.end();
            });
            read2.on('data', (chunk) => {
                // Process the read data
            });
            read2.on('end', () => {
                //console.log('Read process completed');
                resolve();
            });
            read1.on('error', reject);
            read2.on('error', reject);
        });

        await Promise.all([
            writer(write1, 11, 'dummy'),
            readProcess
        ]);
    }

    const endTime = process.hrtime.bigint();
    const duration = (endTime - startTime) / 1000000n; // Convert nanoseconds to milliseconds
    console.log(`Total duration: ${duration} ms`);
}

main().catch(err => console.error('Error:', err));
